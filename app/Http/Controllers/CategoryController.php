<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCategoryRequest $request, AuditService $audit): RedirectResponse
    {
        $category = Category::create([...$request->validated(), 'active' => true]);
        $audit->record(AuditLog::ACTION_CREATE, $category, newValues: $category->getAttributes());

        return to_route('settings.catalogs');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCategoryRequest $request, Category $category, AuditService $audit): RedirectResponse
    {
        $oldValues = $category->getAttributes();
        $category->update($request->validated());
        $audit->record(AuditLog::ACTION_UPDATE, $category, oldValues: $oldValues, newValues: $category->fresh()->getAttributes());

        return to_route('settings.catalogs');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category, AuditService $audit): RedirectResponse
    {
        $oldValues = $category->getAttributes();
        $category->delete();
        $audit->record(AuditLog::ACTION_DELETE, $category, oldValues: $oldValues);

        return to_route('settings.catalogs');
    }
}
