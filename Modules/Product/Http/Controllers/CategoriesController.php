<?php

namespace Modules\Product\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Category;
use Modules\Product\DataTables\ProductCategoriesDataTable;
use Modules\Product\Services\ProductTaxonomyResolver;

class CategoriesController extends Controller
{

    public function index(Request $request, ProductCategoriesDataTable $dataTable) {
        abort_if(Gate::denies('categories.access'), 403);

        if ($request->ajax()) {
            return $dataTable->ajax();
        }

        return $dataTable->render('product::categories.index');
    }


    public function store(Request $request, ProductTaxonomyResolver $resolver): RedirectResponse
    {
        abort_if(Gate::denies('categories.create'), 403);

        $validatedData = $request->validate([
            'category_code' => 'required|unique:categories',
            'category_name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        $parentId = $request->input('parent_id') !== null && $request->input('parent_id') !== ''
            ? (int) $request->input('parent_id')
            : null;

        $resolver->createCategory(
            (string) $validatedData['category_code'],
            (string) $validatedData['category_name'],
            $parentId,
            auth()->id()
        );

        toast('Kategori Produk Ditambah!', 'success');

        return redirect()->back();
    }


    public function edit($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('categories.edit'), 403);

        $category = Category::findOrFail($id);
        $parentCategories = Category::whereNull('parent_id')->where('id', '!=', $id)->orderBy('category_name')->get();

        return view('product::categories.edit', compact('category', 'parentCategories'));
    }


    public function update(Request $request, $id, ProductTaxonomyResolver $resolver): RedirectResponse
    {
        abort_if(Gate::denies('categories.edit'), 403);

        $request->validate([
            'category_code' => 'required|unique:categories,category_code,' . $id,
            'category_name' => 'required|string|max:255',
            'parent_id' => 'nullable|exists:categories,id'
        ]);

        $category = Category::findOrFail($id);
        $parentId = $request->input('parent_id') !== null && $request->input('parent_id') !== ''
            ? (int) $request->input('parent_id')
            : null;

        $resolver->updateCategory(
            $category,
            (string) $request->category_code,
            (string) $request->category_name,
            $parentId
        );

        toast('Kategori Produk Diperbaharui!', 'info');

        return redirect()->route('product-categories.index');
    }


    public function destroy($id): RedirectResponse
    {
        abort_if(Gate::denies('categories.delete'), 403);

        $category = Category::findOrFail($id);

        // Check if the category has associated products
        if ($category->products()->exists()) {
            return back()->withErrors('Tidak dapat dihapus karena ada produk yang terkait dengan kategori ini.');
        }

        // Check if the category has any subcategories
        if ($category->children()->exists()) {
            return back()->withErrors('Tidak dapat dihapus karena ada subkategori yang terkait dengan kategori ini.');
        }

        // If no products or subcategories, delete the category
        $category->delete();

        toast('Kategori Produk Dihapus!', 'warning');

        return redirect()->route('product-categories.index');
    }
}
