<?php

declare(strict_types=1);

namespace App\Platform\Modules;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CoreModuleDependencyRegistry: declares each module's hard prerequisites and persists them for
 * ModuleManager. Every change is validated against the WHOLE prospective graph first (only Core
 * empty, Core always required, no unknown modules, no cycles) and applied atomically.
 */
final class ModuleDependencyRegistry
{
    /**
     * Replace one module's prerequisites.
     *
     * @param list<string> $requires
     * @throws InvalidArgumentException invalid graph
     * @throws ModuleException          an existing tenant would be left with the module enabled but a new prerequisite off
     */
    public function declare(string $module, array $requires): void
    {
        $this->apply([$module => array_values(array_unique($requires))] + $this->current());
    }

    /**
     * Replace the whole registry (used by modules:sync-dependencies).
     *
     * @param array<string, list<string>> $declared
     */
    public function sync(array $declared): void
    {
        $complete = array_fill_keys(array_map(fn (ModuleKey $k) => $k->value, ModuleKey::cases()), []);
        foreach ($declared as $module => $requires) {
            $complete[$module] = array_values(array_unique($requires));
        }
        $this->apply($complete);
    }

    /** @return array<string, list<string>> */
    public function current(): array
    {
        $out = array_fill_keys(array_map(fn (ModuleKey $k) => $k->value, ModuleKey::cases()), []);
        foreach (DB::table('module_dependencies')->orderBy('module_key')->orderBy('requires_key')->get() as $row) {
            $out[$row->module_key][] = $row->requires_key;
        }

        return $out;
    }

    /** @param array<string, list<string>> $target */
    private function apply(array $target): void
    {
        DependencyGraph::validate($target);   // throws before anything is touched

        DB::transaction(function () use ($target): void {
            // Serialise registry changes so two concurrent declarations cannot each pass the cycle
            // check and together create a cycle.
            DB::statement('LOCK TABLE module_dependencies IN SHARE ROW EXCLUSIVE MODE');

            $current = $this->current();
            $added = [];
            foreach ($target as $module => $requires) {
                foreach (array_diff($requires, $current[$module] ?? []) as $newPrereq) {
                    $added[] = [$module, $newPrereq];
                }
            }
            foreach ($added as [$module, $prereq]) {
                $stranded = DB::table('tenant_modules as m')
                    ->where('m.module_key', $module)->where('m.is_enabled', true)
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('tenant_modules as p')
                        ->whereColumn('p.tenant_id', 'm.tenant_id')->where('p.module_key', $prereq)->where('p.is_enabled', true))
                    ->count();
                if ($stranded > 0) {
                    throw ModuleException::strandsTenants($module, $prereq, $stranded);
                }
            }

            DB::table('module_dependencies')->delete();
            $rows = [];
            foreach ($target as $module => $requires) {
                foreach ($requires as $required) {
                    $rows[] = ['module_key' => $module, 'requires_key' => $required];
                }
            }
            DB::table('module_dependencies')->insert($rows);
        });
    }
}
