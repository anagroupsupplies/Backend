<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    /** A customer may not hoard an unbounded number of saved addresses. */
    private const MAX_PER_USER = 10;

    public function index(Request $request): JsonResponse
    {
        $addresses = Address::where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->latest()
            ->get()
            ->map(fn (Address $address) => $this->data($address));

        return response()->json(['data' => $addresses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        abort_if(Address::where('user_id', $request->user()->id)->count() >= self::MAX_PER_USER, 422, 'You can save up to '.self::MAX_PER_USER.' delivery addresses. Please delete one first.');

        $makeDefault = $data['isDefault'] ?? Address::where('user_id', $request->user()->id)->doesntExist();
        $address = Address::create([...$this->attributes($data), 'user_id' => $request->user()->id]);

        if ($makeDefault) {
            $address->makeDefault();
        }

        return response()->json(['data' => $this->data($address->refresh())], 201);
    }

    public function update(Request $request, Address $address): JsonResponse
    {
        $this->assertOwned($request, $address);
        $data = $this->validated($request, updating: true);
        $address->update($this->attributes($data));

        if ($data['isDefault'] ?? false) {
            $address->makeDefault();
        }

        return response()->json(['data' => $this->data($address->refresh())]);
    }

    public function destroy(Request $request, Address $address): JsonResponse
    {
        $this->assertOwned($request, $address);
        $wasDefault = $address->is_default;
        $address->delete();

        // Never leave the customer without a default once they still have one.
        if ($wasDefault && $next = Address::where('user_id', $request->user()->id)->latest()->first()) {
            $next->makeDefault();
        }

        return response()->json([], 204);
    }

    /**
     * A saved address is private to the customer who created it, so another
     * customer guessing an id gets a 404 rather than someone else's details.
     */
    private function assertOwned(Request $request, Address $address): void
    {
        abort_unless($address->user_id === $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'fullName' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255'],
            'phone' => [$required, 'string', 'max:50'],
            'streetAddress' => [$required, 'string', 'max:255'],
            'city' => [$required, 'string', 'max:100'],
            'state' => [$required, 'string', 'max:100'],
            'postalCode' => [$required, 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'],
            'deliveryNotes' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'isDefault' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $map = ['fullName' => 'full_name', 'streetAddress' => 'street_address', 'postalCode' => 'postal_code', 'deliveryNotes' => 'delivery_notes'];
        $attributes = [];

        foreach (['label', 'email', 'phone', 'city', 'state', 'country', 'latitude', 'longitude', 'accuracy'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $data)) {
                $attributes[$to] = $data[$from];
            }
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function data(Address $address): array
    {
        return [
            'id' => (string) $address->id,
            'label' => $address->label,
            'fullName' => $address->full_name,
            'email' => $address->email,
            'phone' => $address->phone,
            'streetAddress' => $address->street_address,
            'city' => $address->city,
            'state' => $address->state,
            'postalCode' => $address->postal_code,
            'country' => $address->country,
            'deliveryNotes' => $address->delivery_notes,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'accuracy' => $address->accuracy,
            'isDefault' => $address->is_default,
        ];
    }
}
