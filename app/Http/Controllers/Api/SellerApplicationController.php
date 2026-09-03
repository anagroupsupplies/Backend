<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use App\Models\Shop;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SellerApplicationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Applications from customers who want to open a shop.
 *
 * Identity documents are deliberately stored on the private disk and streamed
 * to administrators through {@see document()}: a NIDA or passport scan must
 * never sit behind a guessable public URL.
 */
class SellerApplicationController extends Controller
{
    private const PRIVATE_DISK = 'local';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SellerApplicationNotifier $notifier,
    ) {}

    /** The applicant's own application, so they can see where it stands. */
    public function mine(Request $request): JsonResponse
    {
        $application = SellerApplication::where('user_id', $request->user()->id)->latest('id')->first();

        return response()->json(['data' => $application ? $this->data($application) : null]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->isSeller() || $user->isAdmin(), 422, 'This account can already sell on the platform.');

        $existing = SellerApplication::where('user_id', $user->id)->latest('id')->first();
        abort_if($existing && $existing->status === SellerApplication::STATUS_PENDING, 422, 'Your application is already being reviewed.');
        abort_if($existing && $existing->status === SellerApplication::STATUS_APPROVED, 422, 'Your application has already been approved.');

        $data = $this->validated($request, $existing);

        // Where more information was requested, the applicant edits the same
        // application rather than opening a second one.
        $application = $existing && $existing->isEditable() ? $existing : new SellerApplication;
        $application->fill([
            ...$this->attributes($data),
            'user_id' => $user->id,
            'reference' => $application->reference ?? 'APP-'.now()->format('Ymd').'-'.Str::upper(Str::random(5)),
            'status' => SellerApplication::STATUS_PENDING,
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
            'review_notes' => null,
        ])->save();

        $this->audit->record('seller_application.submitted', $application, ['business' => $application->business_name], "{$user->email} applied to become a seller");
        $this->notifier->submitted($application->fresh('user'));

        return response()->json(['data' => $this->data($application->fresh())], 201);
    }

    /** Administrator queue, newest pending first. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(SellerApplication::STATUSES)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        $query = SellerApplication::with('user:id,name,email')->latest('id');

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(fn ($q) => $q->where('business_name', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")
                ->orWhere('full_name', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$search}%")));
        }

        $applications = $query->paginate(20)->withQueryString();

        return response()->json(['data' => [
            'applications' => collect($applications->items())->map(fn (SellerApplication $a) => $this->data($a, forAdmin: true)),
            'meta' => ['total' => $applications->total(), 'currentPage' => $applications->currentPage(), 'lastPage' => $applications->lastPage()],
            'counts' => $this->counts(),
        ]]);
    }

    public function show(SellerApplication $application): JsonResponse
    {
        return response()->json(['data' => $this->data($application->load(['user', 'reviewer']), forAdmin: true)]);
    }

    /**
     * Approve: promote the account to seller and open its shop.
     */
    public function approve(Request $request, SellerApplication $application): JsonResponse
    {
        abort_if($application->status === SellerApplication::STATUS_APPROVED, 422, 'This application was already approved.');

        DB::transaction(function () use ($application, $request): void {
            $applicant = $application->user;
            $applicant->update(['role' => User::ROLE_SELLER, 'phone' => $applicant->phone ?: $application->phone]);

            Shop::firstOrCreate(
                ['seller_id' => $applicant->id],
                [
                    'name' => $application->business_name,
                    'slug' => Str::slug($application->business_name).'-'.$applicant->id,
                    'description' => $application->business_description,
                    'logo' => $application->logo_url,
                    'is_active' => true,
                ],
            );

            $application->forceFill([
                'status' => SellerApplication::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
                'review_notes' => null,
            ])->save();
        });

        $this->audit->record('seller_application.approved', $application, ['business' => $application->business_name, 'user' => $application->user->email], "Approved {$application->business_name} as a seller");
        $this->notifier->approved($application->fresh('user'));

        return response()->json(['data' => $this->data($application->fresh(['user', 'reviewer']), forAdmin: true)]);
    }

    public function reject(Request $request, SellerApplication $application): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        abort_if($application->status === SellerApplication::STATUS_APPROVED, 422, 'An approved application cannot be rejected here.');

        $application->forceFill([
            'status' => SellerApplication::STATUS_REJECTED,
            'rejection_reason' => $data['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit->record('seller_application.rejected', $application, ['reason' => $data['reason']], "Rejected the application from {$application->business_name}");
        $this->notifier->rejected($application->fresh('user'));

        return response()->json(['data' => $this->data($application->fresh(['user', 'reviewer']), forAdmin: true)]);
    }

    /** Send it back to the applicant with a note about what is missing. */
    public function requestInformation(Request $request, SellerApplication $application): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:1000']]);
        abort_if($application->status === SellerApplication::STATUS_APPROVED, 422, 'This application was already approved.');

        $application->forceFill([
            'status' => SellerApplication::STATUS_MORE_INFO,
            'review_notes' => $data['notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $this->audit->record('seller_application.info_requested', $application, ['notes' => $data['notes']], "Requested more information from {$application->business_name}");
        $this->notifier->moreInformationRequested($application->fresh('user'));

        return response()->json(['data' => $this->data($application->fresh(['user', 'reviewer']), forAdmin: true)]);
    }

    /**
     * Upload one supporting file.
     *
     * The shop logo becomes public branding; identity and registration
     * documents go to the private disk and are only ever handed back through
     * the administrator-only {@see document()} route.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['logo', 'id_document', 'business_document'])],
            'file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        $file = $request->file('file');
        abort_if($data['kind'] === 'logo' && $file->extension() === 'pdf', 422, 'The shop logo must be an image.');

        if ($data['kind'] === 'logo') {
            $path = $file->storeAs('shops/logos/'.now()->format('Y/m'), Str::uuid().'.'.$file->extension(), 'public');

            return response()->json(['data' => ['kind' => 'logo', 'url' => url(Storage::disk('public')->url($path)), 'path' => $path]], 201);
        }

        $path = $file->storeAs('seller-applications/'.$request->user()->id, Str::uuid().'.'.$file->extension(), self::PRIVATE_DISK);

        return response()->json(['data' => ['kind' => $data['kind'], 'url' => null, 'path' => $path, 'name' => $file->getClientOriginalName()]], 201);
    }

    /** Stream a private document to a reviewing administrator. */
    public function document(SellerApplication $application, string $kind): StreamedResponse
    {
        $path = match ($kind) {
            'id_document' => $application->id_document_path,
            'business_document' => $application->business_document_path,
            default => null,
        };

        abort_unless($path && Storage::disk(self::PRIVATE_DISK)->exists($path), 404);

        return Storage::disk(self::PRIVATE_DISK)->response($path);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = SellerApplication::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'all' => (int) $counts->sum(),
            ...collect(SellerApplication::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SellerApplication $existing = null): array
    {
        // An applicant correcting their submission keeps the document already
        // on file unless they deliberately upload a replacement.
        $documentHeld = (bool) $existing?->id_document_path;

        return $request->validate([
            'fullName' => ['required', 'string', 'max:255'],
            'businessName' => ['required', 'string', 'max:255'],
            'productCategory' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'alternatePhone' => ['nullable', 'string', 'max:50'],
            'region' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'streetAddress' => ['required', 'string', 'max:255'],
            'businessDescription' => ['required', 'string', 'max:2000'],
            'tinNumber' => ['nullable', 'string', 'max:60'],
            'businessRegistrationNumber' => ['nullable', 'string', 'max:60'],
            'payoutMethod' => ['required', Rule::in(SellerApplication::PAYOUT_METHODS)],
            'payoutAccountName' => ['required', 'string', 'max:255'],
            'payoutNumber' => ['required', 'string', 'max:60'],
            'payoutBank' => ['nullable', 'string', 'max:120'],
            'logoUrl' => ['nullable', 'string', 'max:2048'],
            'idDocumentType' => ['required', Rule::in(SellerApplication::ID_TYPES)],
            'idNumber' => ['required', 'string', 'max:60'],
            'idDocumentPath' => [$documentHeld ? 'nullable' : 'required', 'string', 'max:2048'],
            'businessDocumentPath' => ['nullable', 'string', 'max:2048'],
            // Explicit, auditable consent rather than a silently checked box.
            'acceptTerms' => ['required', 'accepted'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $map = [
            'fullName' => 'full_name', 'businessName' => 'business_name', 'productCategory' => 'product_category',
            'alternatePhone' => 'alternate_phone', 'streetAddress' => 'street_address',
            'businessDescription' => 'business_description', 'tinNumber' => 'tin_number',
            'businessRegistrationNumber' => 'business_registration_number', 'payoutMethod' => 'payout_method',
            'payoutAccountName' => 'payout_account_name', 'payoutNumber' => 'payout_number', 'payoutBank' => 'payout_bank',
            'logoUrl' => 'logo_url', 'idDocumentType' => 'id_document_type', 'idNumber' => 'id_number',
            'idDocumentPath' => 'id_document_path', 'businessDocumentPath' => 'business_document_path',
        ];

        // A form that simply echoes back a blank upload field must not erase the
        // document already held for this application.
        foreach (['idDocumentPath', 'businessDocumentPath'] as $document) {
            if (trim((string) ($data[$document] ?? '')) === '') {
                unset($data[$document]);
            }
        }

        $attributes = [];
        foreach (['phone', 'region', 'city'] as $key) {
            $attributes[$key] = $data[$key];
        }
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data)) {
                $attributes[$to] = $data[$from];
            }
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function data(SellerApplication $a, bool $forAdmin = false): array
    {
        $base = [
            'id' => (string) $a->id,
            'reference' => $a->reference,
            'businessName' => $a->business_name,
            'productCategory' => $a->product_category,
            'status' => $a->status,
            'statusLabel' => match ($a->status) {
                SellerApplication::STATUS_PENDING => 'Pending Approval',
                SellerApplication::STATUS_MORE_INFO => 'More information requested',
                SellerApplication::STATUS_APPROVED => 'Approved',
                default => 'Rejected',
            },
            'reviewNotes' => $a->review_notes,
            'rejectionReason' => $a->rejection_reason,
            'submittedAt' => $a->submitted_at,
            'reviewedAt' => $a->reviewed_at,
            'canEdit' => $a->isEditable(),
            // Echoed back so the applicant can correct a rejected or
            // information-requested submission without retyping everything.
            'fullName' => $a->full_name,
            'phone' => $a->phone,
            'alternatePhone' => $a->alternate_phone,
            'region' => $a->region,
            'city' => $a->city,
            'streetAddress' => $a->street_address,
            'businessDescription' => $a->business_description,
            'tinNumber' => $a->tin_number,
            'businessRegistrationNumber' => $a->business_registration_number,
            'payoutMethod' => $a->payout_method,
            'payoutAccountName' => $a->payout_account_name,
            'payoutNumber' => $a->payout_number,
            'payoutBank' => $a->payout_bank,
            'logoUrl' => $a->logo_url,
            'idDocumentType' => $a->id_document_type,
            'idNumber' => $a->id_number,
            // The scan itself is never exposed, but the applicant must know one
            // is already on file or they cannot resubmit without re-uploading.
            'hasIdDocument' => (bool) $a->id_document_path,
            'hasBusinessDocument' => (bool) $a->business_document_path,
        ];

        if (! $forAdmin) {
            return $base;
        }

        return [
            ...$base,
            'applicant' => $a->user ? ['id' => (string) $a->user->id, 'name' => $a->user->name, 'email' => $a->user->email] : null,
            'termsAcceptedAt' => $a->terms_accepted_at,
            'reviewedBy' => $a->reviewer?->name,
        ];
    }
}
