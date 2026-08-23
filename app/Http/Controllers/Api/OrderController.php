<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Services\MalipoPayService;
use App\Services\OrderNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class OrderController extends Controller
{
    public function __construct(
        private readonly MalipoPayService $malipoPay,
        private readonly OrderNotifier $notifier,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Order::with('items')->where('user_id', $request->user()->id)->latest()->get()->map(fn (Order $order) => $this->data($order))]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id || $request->user()->isAdmin(), 404);

        return response()->json(['data' => $this->data($order->load('items'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shippingDetails' => ['required', 'array'],
            'shippingDetails.fullName' => ['required', 'string', 'max:255'],
            'shippingDetails.email' => ['required', 'email'],
            'shippingDetails.phone' => ['required', 'string', 'max:50'],
            'shippingDetails.streetAddress' => ['required', 'string', 'max:255'],
            'shippingDetails.city' => ['required', 'string', 'max:100'],
            'shippingDetails.state' => ['required', 'string', 'max:100'],
            'shippingDetails.postalCode' => ['required', 'string', 'max:30'],
            'shippingDetails.country' => ['required', 'string', 'max:100'],
            'shippingDetails.deliveryNotes' => ['nullable', 'string', 'max:1000'],
            'paymentMethod' => ['nullable', Rule::in(Order::PAYMENT_METHODS)],
            'paymentPhone' => ['nullable', 'string', 'max:50'],
            'deliveryLocation' => ['nullable', 'array'],
            'deliveryLocation.latitude' => ['required_with:deliveryLocation', 'numeric', 'between:-90,90'],
            'deliveryLocation.longitude' => ['required_with:deliveryLocation', 'numeric', 'between:-180,180'],
            'deliveryLocation.accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $shipping = $validated['shippingDetails'];
        $paymentMethod = $validated['paymentMethod'] ?? Order::PAYMENT_ON_DELIVERY;
        $location = $validated['deliveryLocation'] ?? null;
        $payingByMobileMoney = $paymentMethod === Order::PAYMENT_MOBILE_MONEY;
        $paymentPhone = $validated['paymentPhone'] ?? $shipping['phone'];

        if ($payingByMobileMoney) {
            abort_unless($this->malipoPay->isAvailable(), 503, 'Mobile money is not available right now. Please choose pay on delivery.');
            abort_unless($this->malipoPay->isValidPhone($paymentPhone), 422, 'Enter a valid Tanzanian mobile money number, for example 0712345678.');
        }
        $order = DB::transaction(function () use ($request, $shipping, $paymentMethod, $location, $paymentPhone, $payingByMobileMoney): Order {
            $cart = CartItem::where('user_id', $request->user()->id)->lockForUpdate()->get();
            abort_if($cart->isEmpty(), 422, 'Cart is empty.');
            $products = Product::whereIn('id', $cart->pluck('product_id')->unique())->lockForUpdate()->get()->keyBy('id');
            foreach ($cart as $item) {
                $product = $products->get($item->product_id);
                abort_if(! $product || ! $product->is_active || $item->quantity > $product->stock, 422, ($product?->name ?? 'An item').' is out of stock or does not have the requested quantity available.');
                $item->setRelation('product', $product);
            }
            $subtotal = $cart->sum(fn (CartItem $item) => (float) $item->product->price * $item->quantity);
            $order = Order::create(['number' => 'ANA-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)), 'user_id' => $request->user()->id, 'subtotal' => $subtotal, 'shipping_total' => 0, 'total' => $subtotal, 'status' => 'pending', 'shipping_details' => $shipping, 'payment_method' => $paymentMethod, 'payment_status' => $payingByMobileMoney ? Order::PAY_STATUS_PROCESSING : Order::PAY_STATUS_PENDING, 'payment_phone' => $payingByMobileMoney ? $this->malipoPay->normalisePhone($paymentPhone) : null, 'delivery_latitude' => $location['latitude'] ?? null, 'delivery_longitude' => $location['longitude'] ?? null, 'delivery_accuracy' => $location['accuracy'] ?? null]);
            foreach ($cart as $item) {
                $product = $item->product;
                $order->items()->create(['product_id' => $product->id, 'seller_id' => $product->seller_id, 'shop_id' => $product->shop_id, 'name' => $product->name, 'unit_price' => $product->price, 'quantity' => $item->quantity, 'selected_size' => $item->selected_size, 'sizing_type' => $product->sizing_type, 'image' => $product->image, 'product_snapshot' => ['id' => $product->id, 'sellerId' => $product->seller_id, 'shopId' => $product->shop_id, 'name' => $product->name, 'price' => $product->price]]);
                $product->decrement('stock', $item->quantity);
            }
            CartItem::where('user_id', $request->user()->id)->delete();

            return $order->load('items');
        });

        // The gateway call is deliberately made after the transaction commits so
        // a slow network round trip never holds the stock/cart row locks open.
        $paymentError = $payingByMobileMoney ? $this->requestMobileMoneyPush($order, $paymentPhone) : null;

        $this->notifier->orderPlaced($order);

        return response()->json(['data' => [...$this->data($order), 'paymentError' => $paymentError]], 201);
    }

    /**
     * Push the USSD prompt to the customer. Returns an error message when the
     * push could not be sent, in which case the order is left awaiting payment
     * rather than being silently treated as paid.
     */
    private function requestMobileMoneyPush(Order $order, string $phone): ?string
    {
        try {
            $collection = $this->malipoPay->collect($order, $phone);
        } catch (RuntimeException $exception) {
            report($exception);
            $order->forceFill(['payment_status' => Order::PAY_STATUS_FAILED, 'payment_failure_reason' => $exception->getMessage()])->save();

            return $exception->getMessage();
        }

        $order->forceFill([
            'payment_reference' => $collection['reference'],
            'payment_transaction_id' => $collection['transactionId'],
            'payment_channel' => $collection['channel'],
            'payment_status' => Order::PAY_STATUS_PROCESSING,
        ])->save();

        return null;
    }

    /** @return array<string, mixed> */
    private function data(Order $order): array
    {
        return ['id' => (string) $order->id, 'number' => $order->number, 'userId' => (string) $order->user_id, 'items' => $order->items->map(fn ($item) => ['id' => (string) $item->id, 'productId' => $item->product_id ? (string) $item->product_id : null, 'sellerId' => $item->seller_id ? (string) $item->seller_id : null, 'shopId' => $item->shop_id ? (string) $item->shop_id : null, 'name' => $item->name, 'price' => (float) $item->unit_price, 'quantity' => $item->quantity, 'selectedSize' => $item->selected_size, 'sizingType' => $item->sizing_type, 'image' => $item->image]), 'total' => (float) $order->total, 'shippingDetails' => $order->shipping_details, 'status' => $order->status, 'paymentMethod' => $order->payment_method, 'paymentStatus' => $order->payment_status, 'paymentReference' => $order->payment_reference, 'paymentChannel' => $order->payment_channel, 'paidAmount' => (float) $order->paid_amount, 'paidAt' => $order->paid_at, 'paymentFailureReason' => $order->payment_failure_reason, 'deliveryLocation' => $order->delivery_latitude === null ? null : ['latitude' => $order->delivery_latitude, 'longitude' => $order->delivery_longitude, 'accuracy' => $order->delivery_accuracy], 'createdAt' => $order->created_at, 'updatedAt' => $order->updated_at];
    }
}
