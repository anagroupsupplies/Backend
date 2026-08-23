<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Support conversations. One controller serves customers, sellers and
 * administrators: what each can see is decided by `Ticket::scopeVisibleTo`
 * rather than by which endpoint they call, so the rules cannot drift apart.
 */
class TicketController extends Controller
{
    public function __construct(private readonly TicketNotifier $notifier) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([...Ticket::STATUSES, 'active'])],
            'category' => ['nullable', Rule::in(Ticket::CATEGORIES)],
            'perPage' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $user = $request->user();
        $query = Ticket::query()->visibleTo($user)->with(['user:id,name,email', 'seller:id,name', 'shop:id,name'])
            ->withCount('messages')
            ->orderByRaw("CASE WHEN status IN ('open','pending') THEN 0 ELSE 1 END")
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('reference', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")));
        }
        if ($status = $filters['status'] ?? null) {
            $status === 'active'
                ? $query->whereIn('status', [Ticket::STATUS_OPEN, Ticket::STATUS_PENDING])
                : $query->where('status', $status);
        }
        if ($category = $filters['category'] ?? null) {
            $query->where('category', $category);
        }

        $tickets = $query->paginate($filters['perPage'] ?? 20)->withQueryString();

        return response()->json(['data' => [
            'tickets' => collect($tickets->items())->map(fn (Ticket $ticket) => $this->summary($ticket)),
            'meta' => ['total' => $tickets->total(), 'perPage' => $tickets->perPage(), 'currentPage' => $tickets->currentPage(), 'lastPage' => $tickets->lastPage()],
            'counts' => $this->counts($user),
        ]]);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        abort_unless($ticket->isParticipant($user), 404);

        return response()->json(['data' => $this->detail($ticket, $user)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', Rule::in(Ticket::CATEGORIES)],
            'orderId' => ['nullable', 'exists:orders,id'],
            'productId' => ['nullable', 'exists:products,id'],
            'sellerId' => ['nullable', 'exists:users,id'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['string', 'max:2048'],
        ]);

        $user = $request->user();
        [$order, $product] = [$this->ownedOrder($user, $data['orderId'] ?? null), isset($data['productId']) ? Product::find($data['productId']) : null];
        $seller = $this->resolveSeller($data, $order, $product);

        $ticket = DB::transaction(function () use ($data, $user, $order, $product, $seller): Ticket {
            $ticket = Ticket::create([
                'reference' => 'TKT-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
                'user_id' => $user->id,
                'seller_id' => $seller?->id,
                'shop_id' => $seller?->shop?->id ?? $product?->shop_id,
                'order_id' => $order?->id,
                'product_id' => $product?->id,
                'subject' => $data['subject'],
                'category' => $data['category'] ?? 'other',
                'status' => Ticket::STATUS_OPEN,
                'last_message_at' => now(),
            ]);
            $this->addMessage($ticket, $user, $data['message'], $data['attachments'] ?? null);

            return $ticket;
        });

        $this->notifier->ticketOpened($ticket->fresh(['user', 'seller']));

        return response()->json(['data' => $this->detail($ticket->fresh(), $user)], 201);
    }

    public function reply(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        abort_unless($ticket->isParticipant($user), 404);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:4'],
            'attachments.*' => ['string', 'max:2048'],
            'isInternal' => ['nullable', 'boolean'],
        ]);

        abort_if($ticket->status === Ticket::STATUS_CLOSED, 422, 'This ticket is closed. Please open a new one.');
        // Only staff may leave a note the customer cannot see.
        $internal = ($data['isInternal'] ?? false) && $ticket->isStaff($user);

        $message = $this->addMessage($ticket, $user, $data['message'], $data['attachments'] ?? null, $internal);

        if (! $internal) {
            // A shop reply puts the ball back with the customer, and vice versa.
            $ticket->forceFill([
                'status' => $ticket->isStaff($user) ? Ticket::STATUS_PENDING : Ticket::STATUS_OPEN,
                'last_message_at' => now(),
            ])->save();
            $this->notifier->ticketReplied($ticket->fresh(['user', 'seller']), $user, $data['message']);
        }

        return response()->json(['data' => $this->message($message, $user)], 201);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $user = $request->user();
        abort_unless($ticket->isParticipant($user), 404);

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Ticket::STATUSES)],
            'priority' => ['sometimes', Rule::in(Ticket::PRIORITIES)],
        ]);

        // A customer may resolve or reopen their own ticket, nothing more.
        if (! $ticket->isStaff($user)) {
            abort_if(isset($data['priority']), 403, 'Only the shop can change the priority of a ticket.');
            abort_if(isset($data['status']) && ! in_array($data['status'], [Ticket::STATUS_OPEN, Ticket::STATUS_RESOLVED], true), 403, 'You can only reopen or resolve your ticket.');
        }

        $ticket->forceFill([
            ...array_filter(['status' => $data['status'] ?? null, 'priority' => $data['priority'] ?? null]),
            'closed_at' => isset($data['status']) && in_array($data['status'], [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true) ? now() : null,
        ])->save();

        return response()->json(['data' => $this->detail($ticket->fresh(), $user)]);
    }

    /**
     * @param  array<int, string>|null  $attachments
     */
    private function addMessage(Ticket $ticket, User $author, string $body, ?array $attachments, bool $internal = false): TicketMessage
    {
        return $ticket->messages()->create([
            'user_id' => $author->id,
            'author_name' => $author->name,
            'author_role' => $author->role,
            'body' => $body,
            'attachments' => $attachments ?: null,
            'is_internal' => $internal,
        ]);
    }

    /**
     * A customer may only attach one of their own orders to a ticket.
     */
    private function ownedOrder(User $user, ?int $orderId): ?Order
    {
        if (! $orderId) {
            return null;
        }
        $order = Order::find($orderId);

        return $order && ($order->user_id === $user->id || $user->isAdmin()) ? $order : null;
    }

    /**
     * Work out which shop the ticket belongs to. Anything we cannot attribute
     * to a seller becomes a platform ticket for the administrators.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSeller(array $data, ?Order $order, ?Product $product): ?User
    {
        $sellerId = $data['sellerId'] ?? $product?->seller_id ?? $order?->items()->whereNotNull('seller_id')->value('seller_id');

        if (! $sellerId) {
            return null;
        }
        $seller = User::with('shop')->find($sellerId);

        return $seller?->isSeller() ? $seller : null;
    }

    /** @return array<string, int> */
    private function counts(User $user): array
    {
        $base = fn () => Ticket::query()->visibleTo($user);

        return [
            'all' => $base()->count(),
            'open' => $base()->where('status', Ticket::STATUS_OPEN)->count(),
            'pending' => $base()->where('status', Ticket::STATUS_PENDING)->count(),
            'resolved' => $base()->where('status', Ticket::STATUS_RESOLVED)->count(),
            'closed' => $base()->where('status', Ticket::STATUS_CLOSED)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Ticket $ticket): array
    {
        return [
            'id' => (string) $ticket->id,
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'customer' => $ticket->user ? ['id' => (string) $ticket->user->id, 'name' => $ticket->user->name, 'email' => $ticket->user->email] : null,
            'shopName' => $ticket->shop?->name ?? ($ticket->seller?->name ? $ticket->seller->name : 'Antenkayume support'),
            'orderId' => $ticket->order_id ? (string) $ticket->order_id : null,
            'productId' => $ticket->product_id ? (string) $ticket->product_id : null,
            'messagesCount' => $ticket->messages_count ?? $ticket->messages()->count(),
            'lastMessageAt' => $ticket->last_message_at,
            'createdAt' => $ticket->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Ticket $ticket, User $viewer): array
    {
        $ticket->loadMissing(['user', 'seller', 'shop', 'order', 'product']);
        $messages = $ticket->messages()->get()
            // Internal notes stay between the shop and the administrators.
            ->reject(fn (TicketMessage $message) => $message->is_internal && ! $ticket->isStaff($viewer))
            ->map(fn (TicketMessage $message) => $this->message($message, $viewer))
            ->values();

        return [
            ...$this->summary($ticket),
            'canReply' => $ticket->status !== Ticket::STATUS_CLOSED,
            'isStaffView' => $ticket->isStaff($viewer),
            'orderNumber' => $ticket->order?->number,
            'productName' => $ticket->product?->name,
            'messages' => $messages,
        ];
    }

    /** @return array<string, mixed> */
    private function message(TicketMessage $message, User $viewer): array
    {
        return [
            'id' => (string) $message->id,
            'body' => $message->body,
            'attachments' => $message->attachments ?? [],
            'isInternal' => $message->is_internal,
            'isMine' => $message->user_id === $viewer->id,
            'author' => ['id' => $message->user_id ? (string) $message->user_id : null, 'name' => $message->author_name, 'role' => $message->author_role],
            'createdAt' => $message->created_at,
        ];
    }
}
