<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('managed_server_mtas', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('username');
            $table->foreignId('pmta_virtual_mta_id')->nullable()->after('name');
            $table->unique('pmta_virtual_mta_id', 'managed_server_mta_vmta_unique');
            $table->foreign('pmta_virtual_mta_id', 'managed_server_mta_vmta_fk')
                ->references('id')->on('pmta_virtual_mtas')->cascadeOnDelete();
        });

        DB::table('pmta_virtual_mtas as vmtas')
            ->join('managed_server_ips as ips', function ($join): void {
                $join->on('ips.workspace_id', '=', 'vmtas.workspace_id')
                    ->on('ips.id', '=', 'vmtas.managed_server_ip_id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('managed_server_mtas')
                    ->whereColumn('managed_server_mtas.pmta_virtual_mta_id', 'vmtas.id');
            })
            ->select([
                'vmtas.workspace_id', 'vmtas.managed_server_id', 'vmtas.managed_server_ip_id',
                'vmtas.ip', 'vmtas.domain', 'vmtas.name', 'vmtas.is_active', 'ips.type', 'vmtas.created_by', 'vmtas.id',
                'vmtas.created_at', 'vmtas.updated_at',
            ])
            ->orderBy('vmtas.id')
            ->get()
            ->each(function (object $vmta): void {
                DB::table('managed_server_mtas')->insert([
                    'workspace_id' => $vmta->workspace_id,
                    'managed_server_id' => $vmta->managed_server_id,
                    'managed_server_ip_id' => $vmta->managed_server_ip_id,
                    'ip' => $vmta->ip,
                    'dns' => $vmta->domain,
                    'ip_type' => $vmta->type,
                    'mta_type' => 'vmta',
                    'username' => null,
                    'name' => $vmta->name,
                    'pmta_virtual_mta_id' => $vmta->id,
                    'is_active' => $vmta->is_active,
                    'user_id' => $vmta->created_by,
                    'created_at' => $vmta->created_at,
                    'updated_at' => $vmta->updated_at,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('managed_server_mtas', function (Blueprint $table): void {
            $table->dropForeign('managed_server_mta_vmta_fk');
            $table->dropUnique('managed_server_mta_vmta_unique');
            $table->dropColumn(['pmta_virtual_mta_id', 'name']);
        });
    }
};
