<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CatalogController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $query = Product::query()->with(['category', 'shop'])->where('is_active', true);
        $query->when($request->string('category')->isNotEmpty(), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $request->string('category'))->orWhere('id', $request->input('category'))));
        $query->when($request->string('seller')->isNotEmpty(), fn ($q) => $q->where('seller_id', $request->input('seller')));
        $query->when($request->string('shop')->isNotEmpty(), fn ($q) => $q->whereHas('shop', fn ($s) => $s->where('slug', $request->input('shop'))->orWhere('id', $request->input('shop'))));
        $query->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->input('search').'%')->orWhere('description', 'like', '%'.$request->input('search').'%')));
        $query->when($request->boolean('featured'), fn ($q) => $q->where('featured', true));

        $cacheKey = 'products_list:'.md5(json_encode($request->only(['category', 'seller', 'shop', 'search', 'featured'])));

        return response()->json(['data' => Cache::remember($cacheKey, 30, fn () => $query->latest()->get()->map(fn (Product $product) => $this->productData($product)))]);
    }

    public function product(Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        return response()->json(['data' => Cache::remember("product:{$product->id}", 120, fn () => $this->productData($product->load(['category', 'shop'])))]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => Cache::remember('categories', 120, fn () => Category::where('is_active', true)->orderBy('name')->get()->map(fn (Category $category) => $this->categoryData($category)))]);
    }

    public function group(ProductGroup $productGroup): JsonResponse
    {
        return response()->json(['data' => ['id' => $productGroup->id, 'name' => $productGroup->name, ...($productGroup->data ?? []), 'variants' => Product::with(['category', 'shop'])->where('product_group_id', $productGroup->id)->where('is_active', true)->get()->map(fn (Product $product) => $this->productData($product))]]);
    }

    public function manageableProducts(Request $request): JsonResponse
    {
        $query = Product::query()->with(['category', 'shop', 'seller']);
        if ($request->user()->isSeller() && ! $request->user()->isAdmin()) {
            $query->where('seller_id', $request->user()->id);
        }

        return response()->json(['data' => $query->latest()->get()->map(fn (Product $product) => $this->productData($product))]);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->withOwner($request, $this->validatedProduct($request)));
        $this->clearCache();

        return response()->json(['data' => $this->productData($product->load(['category', 'shop']))], 201);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'variants' => ['nullable', 'array']]);
        $owner = $this->ownerAttributes($request);
        $group = ProductGroup::create(['name' => $data['name'], ...$owner, 'data' => $request->except('variants')]);
        $variantIds = [];
        foreach ($data['variants'] ?? [] as $variant) {
            $variant['groupId'] = $group->id;
            $variantRequest = Request::create('', 'POST', $variant);
            $productData = $this->validatedProduct($variantRequest);
            $productData['product_group_id'] = $group->id;
            $productData = [...$productData, ...$owner];
            $variantIds[] = Product::create($productData)->id;
        }

        return response()->json(['data' => ['id' => (string) $group->id, 'groupId' => (string) $group->id, 'variantIds' => $variantIds]], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->assertCanManageProduct($request->user(), $product);
        $product->update($this->withOwner($request, $this->validatedProduct($request, true)));
        $this->clearCache();

        return response()->json(['data' => $this->productData($product->fresh(['category', 'shop']))]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->assertCanManageProduct(request()->user(), $product);
        $product->delete();
        $this->clearCache();

        return response()->json([], 204);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:categories'], 'description' => ['nullable', 'string'], 'image' => ['nullable', 'string'], 'parentId' => ['nullable', 'exists:categories,id']]);
        $category = Category::create([...$data, 'parent_id' => $data['parentId'] ?? null, 'slug' => Str::slug($data['name'])]);
        Cache::forget('categories');

        return response()->json(['data' => $this->categoryData($category)], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255', 'unique:categories,name,'.$category->id], 'description' => ['nullable', 'string'], 'image' => ['nullable', 'string'], 'isActive' => ['nullable', 'boolean'], 'parentId' => ['nullable', 'exists:categories,id']]);
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        } if (array_key_exists('isActive', $data)) {
            $data['is_active'] = $data['isActive'];
            unset($data['isActive']);
        } if (array_key_exists('parentId', $data)) {
            $data['parent_id'] = $data['parentId'];
            unset($data['parentId']);
        } $category->update($data);
        Cache::forget('categories');

        return response()->json(['data' => $this->categoryData($category)]);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        abort_if($category->products()->exists(), 422, 'Category contains products.');
        $category->delete();
        Cache::forget('categories');

        return response()->json([], 204);
    }

    /** @return array<string, mixed> */
    private function validatedProduct(Request $request, bool $updating = false): array
    {
        $rules = ['name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'], 'categoryId' => ['nullable', 'exists:categories,id'], 'category' => ['nullable', 'string', 'max:255'], 'groupId' => ['nullable', 'exists:product_groups,id'], 'description' => ['nullable', 'string'], 'price' => [$updating ? 'sometimes' : 'required', 'numeric', 'min:0'], 'stock' => ['nullable', 'integer', 'min:0'], 'image' => ['nullable', 'string'],             'images' => ['nullable', 'array', 'max:4'], 'images.*' => ['string'], 'video' => ['nullable', 'array'], 'video.url' => ['nullable', 'string'], 'video.thumbnail' => ['nullable', 'string'], 'sizes' => ['nullable', 'array'], 'sizingType' => ['nullable', 'string'], 'featured' => ['nullable', 'boolean'], 'isActive' => ['nullable', 'boolean'], 'sellerId' => ['nullable', 'exists:users,id'], 'shopId' => ['nullable', 'exists:shops,id']];
        $data = $request->validate($rules);
        $mapped = [];
        foreach (['name', 'description', 'price', 'stock', 'image', 'images', 'video', 'sizes', 'featured'] as $key) {
            if (array_key_exists($key, $data)) {
                $mapped[$key] = $data[$key];
            }
        }
        foreach (['categoryId' => 'category_id', 'groupId' => 'product_group_id', 'sizingType' => 'sizing_type', 'isActive' => 'is_active', 'sellerId' => 'seller_id', 'shopId' => 'shop_id'] as $from => $to) {
            if (array_key_exists($from, $data)) {
                $mapped[$to] = $data[$from];
            }
        }
        if (! isset($mapped['category_id']) && isset($data['category'])) {
            $mapped['category_id'] = Category::where('name', $data['category'])->orWhere('slug', Str::slug($data['category']))->value('id');
        }
        $known = array_keys($rules);
        $extra = $request->except([...$known, 'createdAt', 'updatedAt']);
        if ($extra !== []) {
            $mapped['data'] = [...($updating ? ($request->route('product')?->data ?? []) : []), ...$extra];
        }
        if (isset($mapped['name'])) {
            $mapped['slug'] = Str::slug($mapped['name']).'-'.Str::lower(Str::random(6));
        }

        return $mapped;
    }

    /** @return array<string, mixed> */
    private function productData(Product $product): array
    {
        return ['id' => (string) $product->id, 'sellerId' => $product->seller_id ? (string) $product->seller_id : null, 'shopId' => $product->shop_id ? (string) $product->shop_id : null, 'shopName' => $product->shop?->name, 'shopSlug' => $product->shop?->slug, 'name' => $product->name, 'description' => $product->description, 'price' => (float) $product->price, 'stock' => $product->stock, 'image' => $product->image, 'images' => $product->images, 'video' => $product->video, 'sizes' => $product->sizes, 'sizingType' => $product->sizing_type, 'featured' => $product->featured, 'isActive' => $product->is_active, 'category' => $product->category?->name, 'categoryId' => $product->category_id ? (string) $product->category_id : null, 'groupId' => $product->product_group_id ? (string) $product->product_group_id : null, 'createdAt' => $product->created_at, ...($product->data ?? [])];
    }

    /** @return array<string, mixed> */
    private function categoryData(Category $category): array
    {
        return ['id' => (string) $category->id, 'parentId' => $category->parent_id ? (string) $category->parent_id : null, 'name' => $category->name, 'slug' => $category->slug, 'description' => $category->description, 'image' => $category->image, ...($category->metadata ?? [])];
    }

    /** @return array<string, mixed> */
    private function withOwner(Request $request, array $data): array
    {
        if ($request->user()?->isSeller() && ! $request->user()->isAdmin()) {
            unset($data['featured']);

            return [...$data, ...$this->ownerAttributes($request)];
        }

        return $data;
    }

    /** @return array{seller_id?: int, shop_id?: int} */
    private function ownerAttributes(Request $request): array
    {
        $user = $request->user();
        if (! $user?->isSeller() || $user->isAdmin()) {
            return [];
        }

        $shop = Shop::firstOrCreate(
            ['seller_id' => $user->id],
            ['name' => $user->name."'s Shop", 'slug' => Str::slug($user->name.' shop').'-'.$user->id],
        );

        return ['seller_id' => $user->id, 'shop_id' => $shop->id];
    }

    private function assertCanManageProduct(?User $user, Product $product): void
    {
        abort_unless($user && ($user->isAdmin() || $user->ownsProduct($product)), 403, 'You can only manage products that belong to your shop.');
    }

    private function clearCache(): void
    {
        Cache::forget('products_list');
        Cache::forget('categories');
    }
}
