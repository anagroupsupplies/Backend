<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CatalogController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $query = Product::query()->with('category')->where('is_active', true);
        $query->when($request->string('category')->isNotEmpty(), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $request->string('category'))->orWhere('id', $request->input('category'))));
        $query->when($request->string('search')->isNotEmpty(), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->input('search').'%')->orWhere('description', 'like', '%'.$request->input('search').'%')));
        $query->when($request->boolean('featured'), fn ($q) => $q->where('featured', true));

        return response()->json(['data' => $query->latest()->get()->map(fn (Product $product) => $this->productData($product))]);
    }

    public function product(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->productData($product->load('category'))]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => Category::where('is_active', true)->orderBy('name')->get()->map(fn (Category $category) => $this->categoryData($category))]);
    }

    public function group(ProductGroup $productGroup): JsonResponse
    {
        return response()->json(['data' => ['id' => $productGroup->id, 'name' => $productGroup->name, ...($productGroup->data ?? []), 'variants' => Product::where('product_group_id', $productGroup->id)->get()->map(fn (Product $product) => $this->productData($product))]]);
    }

    public function store(Request $request): JsonResponse
    {
        $product = Product::create($this->validatedProduct($request));

        return response()->json(['data' => $this->productData($product->load('category'))], 201);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'variants' => ['nullable', 'array']]);
        $group = ProductGroup::create(['name' => $data['name'], 'data' => $request->except('variants')]);
        $variantIds = [];
        foreach ($data['variants'] ?? [] as $variant) {
            $variant['groupId'] = $group->id;
            $variantRequest = Request::create('', 'POST', $variant);
            $productData = $this->validatedProduct($variantRequest);
            $productData['product_group_id'] = $group->id;
            $variantIds[] = Product::create($productData)->id;
        }

        return response()->json(['data' => ['id' => (string) $group->id, 'groupId' => (string) $group->id, 'variantIds' => $variantIds]], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $product->update($this->validatedProduct($request, true));

        return response()->json(['data' => $this->productData($product->fresh('category'))]);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json([], 204);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'unique:categories'], 'description' => ['nullable', 'string'], 'image' => ['nullable', 'string']]);
        $category = Category::create([...$data, 'slug' => Str::slug($data['name'])]);

        return response()->json(['data' => $this->categoryData($category)], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255', 'unique:categories,name,'.$category->id], 'description' => ['nullable', 'string'], 'image' => ['nullable', 'string'], 'isActive' => ['nullable', 'boolean']]);
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        } if (array_key_exists('isActive', $data)) {
            $data['is_active'] = $data['isActive'];
            unset($data['isActive']);
        } $category->update($data);

        return response()->json(['data' => $this->categoryData($category)]);
    }

    public function destroyCategory(Category $category): JsonResponse
    {
        abort_if($category->products()->exists(), 422, 'Category contains products.');
        $category->delete();

        return response()->json([], 204);
    }

    /** @return array<string, mixed> */
    private function validatedProduct(Request $request, bool $updating = false): array
    {
        $rules = ['name' => [$updating ? 'sometimes' : 'required', 'string', 'max:255'], 'categoryId' => ['nullable', 'exists:categories,id'], 'category' => ['nullable', 'string', 'max:255'], 'groupId' => ['nullable', 'exists:product_groups,id'], 'description' => ['nullable', 'string'], 'price' => [$updating ? 'sometimes' : 'required', 'numeric', 'min:0'], 'stock' => ['nullable', 'integer', 'min:0'], 'image' => ['nullable', 'string'], 'images' => ['nullable', 'array'], 'sizes' => ['nullable', 'array'], 'sizingType' => ['nullable', 'string'], 'featured' => ['nullable', 'boolean'], 'isActive' => ['nullable', 'boolean']];
        $data = $request->validate($rules);
        $mapped = [];
        foreach (['name', 'description', 'price', 'stock', 'image', 'images', 'sizes', 'featured'] as $key) {
            if (array_key_exists($key, $data)) {
                $mapped[$key] = $data[$key];
            }
        }
        foreach (['categoryId' => 'category_id', 'groupId' => 'product_group_id', 'sizingType' => 'sizing_type', 'isActive' => 'is_active'] as $from => $to) {
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
        return ['id' => (string) $product->id, 'name' => $product->name, 'description' => $product->description, 'price' => (float) $product->price, 'stock' => $product->stock, 'image' => $product->image, 'images' => $product->images, 'sizes' => $product->sizes, 'sizingType' => $product->sizing_type, 'featured' => $product->featured, 'category' => $product->category?->name, 'categoryId' => $product->category_id ? (string) $product->category_id : null, 'groupId' => $product->product_group_id ? (string) $product->product_group_id : null, 'createdAt' => $product->created_at, ...($product->data ?? [])];
    }

    /** @return array<string, mixed> */
    private function categoryData(Category $category): array
    {
        return ['id' => (string) $category->id, 'name' => $category->name, 'slug' => $category->slug, 'description' => $category->description, 'image' => $category->image, ...($category->metadata ?? [])];
    }
}
