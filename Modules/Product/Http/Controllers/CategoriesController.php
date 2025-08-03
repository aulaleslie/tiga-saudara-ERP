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

class CategoriesController extends Controller
{

    public function index(ProductCategoriesDataTable $dataTable) {
        abort_if(Gate::denies('categories.access'), 403);

        return $dataTable->render('product::categories.index');
    }


    public function store(Request $request): RedirectResponse
    {
        abort_if(Gate::denies('categories.create'), 403);

        $validatedData = $request->validate([
            'category_code' => 'required|unique:categories',
            'category_name' => 'required',
            'parent_id' => 'nullable|exists:categories,id',
        ]);

        $category = new Category([
            'category_code' => $validatedData['category_code'],
            'category_name' => $validatedData['category_name'],
            'parent_id' => $request->input('parent_id'), // This will be null if the checkbox is not checked
            'created_by' => auth()->id(), // Assuming you are tracking the user who created the category
            'setting_id' => session('setting_id'), // Assuming you use session to get the current setting
        ]);

        $category->save();

        toast('Kategori Produk Ditambah!', 'success');

        return redirect()->back();
    }


    public function edit($id): Factory|Application|View|\Illuminate\Contracts\Foundation\Application
    {
        abort_if(Gate::denies('categories.edit'), 403);

        $currentSettingId = session('setting_id');

        $category = Category::findOrFail($id);
        $parentCategories = Category::whereNull('parent_id')->where('setting_id', $currentSettingId)->where('id', '!=', $id)->get(); // Exclude current category to prevent self-referencing

        return view('product::categories.edit', compact('category', 'parentCategories'));
    }


    public function update(Request $request, $id): RedirectResponse
    {
        abort_if(Gate::denies('categories.edit'), 403);

        $request->validate([
            'category_code' => 'required|unique:categories,category_code,' . $id,
            'category_name' => 'required',
            'parent_id' => 'nullable|exists:categories,id' // Ensure parent_id is a valid category ID or null
        ]);

        $category = Category::findOrFail($id);
        $category->update([
            'category_code' => $request->category_code,
            'category_name' => $request->category_name,
            'parent_id' => $request->parent_id // Update the parent_id, can be null if no parent is selected
        ]);

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
