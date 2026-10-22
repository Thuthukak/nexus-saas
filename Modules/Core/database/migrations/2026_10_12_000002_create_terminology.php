<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-curated defaults per business type. Central (no tenant_id, no RLS): shared reference data.
        Schema::create('terminology_mappings', function (Blueprint $t) {
            $t->string('business_type_key', 60);   // slug of the tenant's business_type, e.g. "healthcare"
            $t->string('generic_term', 40);        // Core's internal name, e.g. "Party"
            $t->string('singular', 40);
            $t->string('plural', 40);
            $t->primary(['business_type_key', 'generic_term']);
        });

        // A tenant's own wording wins over the business-type default.
        Schema::create('tenant_terminology', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('generic_term', 40);
            $t->string('singular', 40);
            $t->string('plural', 40);
            $t->timestamps();
            $t->primary(['tenant_id', 'generic_term']);
        });
        Rls::enable('tenant_terminology');

        $rows = [
            ['retail', 'Customer', 'Customers'], ['ecommerce', 'Customer', 'Customers'], ['other', 'Customer', 'Customers'],
            ['restaurant', 'Guest', 'Guests'], ['hospitality', 'Guest', 'Guests'],
            ['professional-services', 'Client', 'Clients'], ['consulting', 'Client', 'Clients'],
            ['legal', 'Client', 'Clients'], ['construction', 'Client', 'Clients'],
            ['healthcare', 'Patient', 'Patients'], ['medical', 'Patient', 'Patients'],
            ['education', 'Student', 'Students'], ['school', 'Student', 'Students'],
        ];
        DB::table('terminology_mappings')->insert(array_map(
            fn ($r) => ['business_type_key' => $r[0], 'generic_term' => 'Party', 'singular' => $r[1], 'plural' => $r[2]],
            $rows,
        ));
    }

    public function down(): void
    {
        Rls::disable('tenant_terminology');
        Schema::dropIfExists('tenant_terminology');
        Schema::dropIfExists('terminology_mappings');
    }
};
