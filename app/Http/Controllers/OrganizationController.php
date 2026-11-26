<?php

namespace App\Http\Controllers;

use App\Models\Divisi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

abstract class OrganizationController extends Controller
{
    protected const PARENT = null;

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        $records = $this->query($request)->orderBy(static::NAME)->orderBy('id')
            ->paginate(in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10)->withQueryString();

        return view('organization.index', [...$this->viewData(), 'records' => $records]);
    }

    public function store(Request $request)
    {
        $this->save($request, new (static::MODEL));

        return $this->saved($request, 'Data berhasil ditambahkan.', 201);
    }

    public function edit(Request $request, string $uuid)
    {
        $record = $this->record($uuid);

        if ($request->expectsJson()) {
            return response()->json($record);
        }

        return view('organization.edit', [...$this->viewData(), 'record' => $record]);
    }

    public function update(Request $request, string $uuid)
    {
        $this->save($request, $this->record($uuid));

        return $this->saved($request, 'Data berhasil diperbarui.');
    }

    public function destroy(Request $request, string $uuid)
    {
        $record = $this->record($uuid);
        if ($this->isReferenced($record)) {
            return $this->inUse($request);
        }

        try {
            $record->delete();
        } catch (QueryException $exception) {
            if ($this->isReferenced($record)) {
                return $this->inUse($request);
            }

            throw $exception;
        }

        return $this->saved($request, 'Data berhasil dihapus.');
    }

    protected function dataTable(Request $request)
    {
        return DataTables::eloquent($this->query($request))->addIndexColumn()->toJson();
    }

    private function query(Request $request): Builder
    {
        $query = (static::MODEL)::query()->withCount(static::CHILDREN);
        $parent = static::PARENT;
        if ($parent) {
            $query->with($parent['relation']);
            if ($request->filled($parent['field'])) {
                $query->where($parent['field'], $request->string($parent['field'])->toString());
            }
        }

        $search = $request->string('search')->trim()->toString();
        if ($search !== '') {
            $query->where(fn (Builder $items) => $items->whereLike(static::CODE, '%'.$search.'%')
                ->orWhereLike(static::NAME, '%'.$search.'%'));
        }

        if (in_array($request->query('status'), ['0', '1'], true)) {
            $query->where('is_active', $request->boolean('status'));
        }

        return $query;
    }

    private function viewData(): array
    {
        $parent = static::PARENT;
        $parents = collect();
        if ($parent) {
            $parents = ($parent['model'])::orderBy($parent['name'])
                ->when($parent['model'] === Divisi::class, fn (Builder $query) => $query->with('departemen'))
                ->get();
        }

        return [
            'resource' => static::RESOURCE,
            'label' => static::LABEL,
            'codeField' => static::CODE,
            'nameField' => static::NAME,
            'parent' => $parent,
            'parents' => $parents,
            'childCount' => static::CHILDREN[0].'_count',
        ];
    }

    private function record(string $uuid): Model
    {
        return (static::MODEL)::where('uuid', $uuid)->firstOrFail();
    }

    private function save(Request $request, Model $record): void
    {
        $parent = static::PARENT;
        $rules = [
            static::CODE => ['required', 'string', 'max:20'],
            static::NAME => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ];
        if ($parent) {
            $parentModel = $parent['model'];
            $rules[$parent['field']] = ['required', 'uuid', Rule::exists((new $parentModel)->getTable(), 'uuid')];
        }
        $data = $request->validate($rules);
        $data[static::CODE] = strtoupper($data[static::CODE]);

        $duplicates = $record->newQuery()->whereRaw('UPPER('.static::CODE.') = ?', [$data[static::CODE]]);
        if ($record->exists) {
            $duplicates->whereKeyNot($record->getKey());
        }
        if ($parent) {
            $field = $parent['field'];
            $duplicates->where($field, $data[$field]);
            if ($record->{$field} !== $data[$field]) {
                $selected = ($parent['model'])::where('uuid', $data[$field])->first();
                if (! $selected?->is_active || ($selected instanceof Divisi && ! $selected->departemen->is_active)) {
                    throw ValidationException::withMessages([$field => 'Pilih unit organisasi yang aktif.']);
                }
            }
        }

        if ($duplicates->exists()) {
            $this->duplicateCode();
        }

        try {
            $record->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            $this->duplicateCode();
        }
    }

    private function duplicateCode(): never
    {
        throw ValidationException::withMessages([static::CODE => 'Kode ini sudah digunakan.']);
    }

    private function isReferenced(Model $record): bool
    {
        foreach (static::CHILDREN as $relationship) {
            if ($record->{$relationship}()->exists()) {
                return true;
            }
        }

        return false;
    }

    private function inUse(Request $request)
    {
        $message = static::LABEL.' tidak dapat dihapus karena masih digunakan.';

        return $request->expectsJson()
            ? response()->json(['success' => false, 'message' => $message], 422)
            : back()->with('error', $message);
    }

    private function saved(Request $request, string $message, int $status = 200)
    {
        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message], $status)
            : redirect()->route(static::RESOURCE.'.index')->with('success', $message);
    }
}
