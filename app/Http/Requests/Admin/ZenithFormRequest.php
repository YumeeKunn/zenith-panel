<?php

namespace Pterodactyl\Http\Requests\Admin;

class ZenithFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'notify_cpu_warn' => 'nullable|integer|between:1,100',
            'notify_mem_warn' => 'nullable|integer|between:1,100',
            'notify_disk_warn' => 'nullable|integer|between:1,100',
            'notify_node_cpu_warn' => 'nullable|integer|between:1,100',
            'notify_node_mem_warn' => 'nullable|integer|between:1,100',
            'notify_cooldown' => 'nullable|integer|min:60',
            'watchdog_max_restarts' => 'nullable|integer|between:1,10',
            'watchdog_restart_window' => 'nullable|integer|min:60',
            'watchdog_cpu_threshold' => 'nullable|integer|between:1,1000',
            'watchdog_cpu_minutes' => 'nullable|integer|between:1,60',
            'watchdog_oom_limit' => 'nullable|integer|between:1,10',
            'watchdog_disk_warn' => 'nullable|integer|between:1,100',
            'discord_webhook' => 'nullable|string|max:500',
        ];
    }
}
