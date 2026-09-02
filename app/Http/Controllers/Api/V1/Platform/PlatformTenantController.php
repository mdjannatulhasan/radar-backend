<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use SmsCore\Models\Tenant;
use SmsCore\Models\TenantProduct;
use SmsCore\Services\TenantProvisioner;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform console API: which schools exist, create one, and set what a
 * school has bought.
 *
 * Scope note, because it is the whole security story of this controller: these
 * endpoints read and write the CENTRAL tables only — tenants, tenant_products.
 * Nothing here opens a tenant schema, and a platform admin's token is not
 * usable against /api/v1/pps/*. Listing a school does not read its students,
 * and enabling a product does not create an account inside it. Support access
 * into a school's data would be a separate, deliberate impersonation feature
 * with its own audit trail; it is not this.
 */
class PlatformTenantController extends Controller
{
    public function __construct(private readonly TenantProvisioner $provisioner) {}

    public function index(): JsonResponse
    {
        $tenants = Tenant::query()
            ->with('products')
            ->orderBy('slug')
            ->get()
            ->map(fn (Tenant $tenant): array => $this->tenantPayload($tenant));

        return response()->json([
            'tenants' => $tenants,
            // The catalogue the console offers, so the UI does not hardcode it.
            'products' => config('sms-core.products'),
            'statuses' => TenantProvisioner::PRODUCT_STATUSES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // The slug becomes both a subdomain label and a Postgres schema
            // name, so it is restricted to what is safe in both.
            'slug' => [
                'required', 'string', 'min:2', 'max:40',
                'regex:/^[a-z][a-z0-9-]*[a-z0-9]$/',
                // 'central.' pins the presence check to the central
                // connection. The default connection's search_path is tenancy's
                // to move; the tenants table is never anywhere but public.
                Rule::unique('central.tenants', 'slug'),
                Rule::notIn(['www', 'admin', 'api', 'app', 'mail', 'static']),
            ],
            'name' => ['required', 'string', 'max:255'],
            'products' => ['sometimes', 'array'],
            'products.*' => ['string', Rule::in(array_keys(config('sms-core.products')))],
            'status' => ['sometimes', Rule::in(TenantProvisioner::PRODUCT_STATUSES)],
        ]);

        try {
            $tenant = $this->provisioner->provision(
                $data['slug'],
                $data['name'],
                $data['products'] ?? ['radar'],
                $data['status'] ?? 'trial',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json(
            ['tenant' => $this->tenantPayload($tenant->load('products'))],
            Response::HTTP_CREATED
        );
    }

    /**
     * Set a product's subscription state for one tenant.
     *
     * Route-model binding is by slug for the tenant; the product is the string
     * key ('radar'), not a row id, because that is what the middleware gates on.
     */
    public function updateProduct(Request $request, string $tenant, string $product): JsonResponse
    {
        $model = Tenant::where('slug', $tenant)->first();

        if (! $model) {
            return response()->json(['message' => 'Tenant not found.'], Response::HTTP_NOT_FOUND);
        }

        if (! array_key_exists($product, config('sms-core.products'))) {
            return response()->json(['message' => 'Unknown product.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in(TenantProvisioner::PRODUCT_STATUSES)],
            'plan' => ['sometimes', 'nullable', 'string', 'max:40'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $row = TenantProduct::updateOrCreate(
            ['tenant_id' => $model->id, 'product' => $product],
            // Only the keys actually sent are written, so a PATCH that carries
            // just a status does not silently blank an expiry date.
            array_intersect_key($data, array_flip(['status', 'plan', 'trial_ends_at', 'expires_at']))
        );

        $model->load('products');

        return response()->json([
            'product' => $this->productPayload($row, $model),
            'tenant' => $this->tenantPayload($model),
        ]);
    }

    private function tenantPayload(Tenant $tenant): array
    {
        return [
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'provisioning_status' => $tenant->provisioning_status,
            'migrated_at' => $tenant->migrated_at?->toIso8601String(),
            'products' => $tenant->products
                ->map(fn (TenantProduct $p): array => $this->productPayload($p, $tenant))
                ->values(),
        ];
    }

    /**
     * The owning tenant is passed in rather than read off the relation: every
     * caller already holds it, and hasProduct() re-queries anyway, so lazily
     * loading it here would add one SELECT per product for nothing.
     */
    private function productPayload(TenantProduct $product, Tenant $tenant): array
    {
        return [
            'product' => $product->product,
            'status' => $product->status,
            'plan' => $product->plan,
            'trial_ends_at' => $product->trial_ends_at?->toIso8601String(),
            'expires_at' => $product->expires_at?->toIso8601String(),
            // Whether the gate would actually let this product through right
            // now. The status column alone does not say: an 'active' row that
            // is past expires_at, or a trial past trial_ends_at, is not
            // enabled. This asks the same method EnsureProductEnabled asks, so
            // the console cannot disagree with the gate.
            'enabled' => $tenant->hasProduct($product->product),
        ];
    }
}
