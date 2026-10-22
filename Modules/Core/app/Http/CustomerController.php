<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Parties\EmailAlreadyUsed;
use Modules\Core\Parties\Party;
use Modules\Core\Parties\PartyService;
use Modules\Core\Records\RecordMetadataService;

final class CustomerController
{
    public function __construct(private readonly PartyService $parties, private readonly RecordMetadataService $records)
    {
    }

    /** The directory page. Any signed-in workspace user may look; only managers may change (see store/update). */
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $query = Party::query()->orderBy('name')->orderBy('id');
        if ($q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }

        return Inertia::render('Core/Customers', [
            'customers' => $query->paginate(25)->withQueryString()->through(fn (Party $p) => self::payload($p)),
            'q' => $q,
            'canManage' => $request->user('workspace')->can('core.customers.manage'),
        ]);
    }

    /** Customer profile: the record plus every ENABLED module's metadata namespace, and the extension tabs. */
    public function show(Request $request, int $id): Response
    {
        $party = Party::query()->whereKey($id)->first();
        abort_if($party === null, 404);

        return Inertia::render('Core/CustomerProfile', [
            'customer' => self::payload($party),
            'metadata' => (object) $this->records->mergedFor('customers', (string) $party->id),
            'canManage' => $request->user('workspace')->can('core.customers.manage'),
        ]);
    }

    /** StoreCustomer: 201 (created) or 200 (existing email updated) CustomerPayload | 403 | 422. */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user('workspace')->can('core.customers.manage')) {
            return response()->json(['message' => 'Missing permission to manage customers'], 403);
        }

        $v = $this->validator($request, creating: true);
        if ($v->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        ['party' => $party, 'created' => $created] = $this->parties->store($this->data($v->validated(), $request));

        return response()->json(self::payload($party), $created ? 201 : 200);
    }

    /** Update by id (the only way to change an email). 200 | 403 | 404 | 409 | 422. */
    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user('workspace')->can('core.customers.manage')) {
            return response()->json(['message' => 'Missing permission to manage customers'], 403);
        }

        $v = $this->validator($request, creating: false);
        if ($v->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $v->errors()], 422);
        }

        try {
            $party = $this->parties->update($id, $this->data($v->validated(), $request));
        } catch (EmailAlreadyUsed $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return $party === null ? response()->json(['message' => 'Not found'], 404) : response()->json(self::payload($party));
    }

    private function validator(Request $request, bool $creating): \Illuminate\Validation\Validator
    {
        $required = $creating ? 'required' : 'sometimes';

        return Validator::make($request->all(), [
            'customer_name' => [$required, 'string', 'max:150', 'min:1'],
            'email' => [$required, 'string', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]{5,30}$/'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'tax_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9\-\/\s.]+$/'],
        ]);
    }

    /**
     * Maps API names to columns, keeping only keys the caller actually sent (absent = unchanged).
     *
     * @param  array<string, mixed>  $valid
     * @return array<string, mixed>
     */
    private function data(array $valid, Request $request): array
    {
        $map = ['customer_name' => 'name', 'email' => 'email', 'phone' => 'phone', 'billing_address' => 'billing_address', 'tax_number' => 'tax_number'];
        $out = [];
        foreach ($map as $in => $column) {
            if ($request->has($in)) {
                $value = $valid[$in] ?? null;
                $out[$column] = is_string($value) ? trim($value) : $value;
                if ($out[$column] === '' && $column !== 'name' && $column !== 'email') {
                    $out[$column] = null;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public static function payload(Party $p): array
    {
        return [
            'customer_id' => $p->id, 'customer_name' => $p->name, 'email' => $p->email,
            'phone' => $p->phone, 'billing_address' => $p->billing_address, 'tax_number' => $p->tax_number,
        ];
    }
}
