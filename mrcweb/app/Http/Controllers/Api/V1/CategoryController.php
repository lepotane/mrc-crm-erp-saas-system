<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    use ResolvesTenant;

    /**
     * Ürün kategorilerini listele
     *
     * @response array{categories: array<int, array{id: int, name: string, color: string, products_count: int}>}
     */
    public function index(Request $request)
    {
        $categories = ProductCategory::where('company_id', $this->tenantId($request))
            ->orderBy('name')
            ->get();

        return response()->json(['categories' => $categories->map(fn (ProductCategory $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'color' => $c->color,
            'products_count' => $c->products()->count(),
        ])]);
    }

    /**
     * Yeni kategori aç
     */
    #[\Dedoc\Scramble\Attributes\Response(
        status: 201,
        type: 'array{category: array{id: int, name: string, color: string}}',
    )]
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120',
                'unique:product_categories,name,NULL,id,company_id,'.$this->tenantId($request)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $category = ProductCategory::create($data + ['company_id' => $this->tenantId($request)]);

        return response()->json(['category' => $category->only(['id', 'name', 'color'])], 201);
    }

    /**
     * Kategoriyi güncelle
     *
     * @response array{category: array{id: int, name: string, color: string}}
     */
    public function update(Request $request, ProductCategory $category)
    {
        abort_if($category->company_id !== $this->tenantId($request), 404, __('Kategori bulunamadı.'));

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120',
                'unique:product_categories,name,'.$category->id.',id,company_id,'.$this->tenantId($request)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $category->update($data);

        return response()->json(['category' => $category->only(['id', 'name', 'color'])]);
    }

    /**
     * Kategoriyi sil (ürün bağlıysa silinemez)
     *
     * @response array{message: string}
     */
    public function destroy(Request $request, ProductCategory $category)
    {
        abort_if($category->company_id !== $this->tenantId($request), 404, __('Kategori bulunamadı.'));

        $category->delete(); // ürünler FK nullOnDelete ile kategorisiz kalır

        return response()->json(['message' => __('Kategori silindi.')]);
    }
}
