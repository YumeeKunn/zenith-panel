@extends('layouts.admin')

@section('title')
    Zenith Control
@endsection

@section('content-header')
    <h1>Zenith Control Center<small>Watchdog, Discord Notifier & overload thresholds — sab yahan se control karo.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Zenith Control</li>
    </ol>
@endsection

@section('content')
    @php
        $statusBadge = function ($active, $every) {
            return $active
                ? '<span class="label label-success">ACTIVE (every ' . $every . ')</span>'
                : '<span class="label label-danger">STOPPED</span>';
        };
    @endphp

    {{-- ============ SERVICES STATUS + POWER CONTROLS ============ --}}
    <div class="row">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">🐕 Watchdog <span style="float:right">{!! ($statusBadge)($watchdog["timer_active"], "60s") !!}</span></h3>
                </div>
                <div class="box-body">
                    <p class="text-muted" style="margin-top:0">Crash-loop guard, OOM abuse cutoff, CPU overload suspend, disk warnings.</p>
                    @if (!empty($watchdogLast))
                        <div style="background:#1e2229;color:#c5d1de;font-family:monospace;font-size:11px;padding:8px;border-radius:4px;max-height:110px;overflow:auto">
                            @foreach ($watchdogLast as $line)
                                {{ $line }}<br>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="box-footer">
                    <form action="{{ route('admin.zenith.service', ['service' => 'ptero-watchdog', 'action' => 'restart']) }}" method="POST" style="display:inline">
                        @csrf
                        <button class="btn btn-sm btn-warning" @unless ($watchdog['timer_active']) disabled @endunless>↻ Restart</button>
                    </form>
                    @if ($watchdog['timer_active'])
                        <form action="{{ route('admin.zenith.service', ['service' => 'ptero-watchdog', 'action' => 'stop']) }}" method="POST" style="display:inline">
                            @csrf
                            <button class="btn btn-sm btn-danger">■ Stop</button>
                        </form>
                    @else
                        <form action="{{ route('admin.zenith.service', ['service' => 'ptero-watchdog', 'action' => 'start']) }}" method="POST" style="display:inline">
                            @csrf
                            <button class="btn btn-sm btn-success">▶ Start</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">📢 Discord Notifier <span style="float:right">{!! ($statusBadge)($notifier["timer_active"], "120s") !!}</span></h3>
                </div>
                <div class="box-body">
                    <p class="text-muted" style="margin-top:0">Node down / high load / server overload alerts + fix suggestions, har 2 min check.</p>
                    @if (!empty($notifierLast))
                        <div style="background:#1e2229;color:#c5d1de;font-family:monospace;font-size:11px;padding:8px;border-radius:4px;max-height:110px;overflow:auto">
                            @foreach ($notifierLast as $line)
                                {{ $line }}<br>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="box-footer">
                    <form action="{{ route('admin.zenith.service', ['service' => 'ptero-notify', 'action' => 'restart']) }}" method="POST" style="display:inline">
                        @csrf
                        <button class="btn btn-sm btn-warning" @unless ($notifier['timer_active']) disabled @endunless>↻ Restart</button>
                    </form>
                    @if ($notifier['timer_active'])
                        <form action="{{ route('admin.zenith.service', ['service' => 'ptero-notify', 'action' => 'stop']) }}" method="POST" style="display:inline">
                            @csrf
                            <button class="btn btn-sm btn-danger">■ Stop</button>
                        </form>
                    @else
                        <form action="{{ route('admin.zenith.service', ['service' => 'ptero-notify', 'action' => 'start']) }}" method="POST" style="display:inline">
                            @csrf
                            <button class="btn btn-sm btn-success">▶ Start</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ============ SETTINGS FORM ============ --}}
    <form action="{{ route('admin.zenith.update') }}" method="POST">
        @csrf
        <div class="row">
            {{-- Notifier thresholds --}}
            <div class="col-md-6">
                <div class="box box-warning">
                    <div class="box-header with-border"><h3 class="box-title">Notifier Thresholds (Discord alerts)</h3></div>
                    <div class="box-body">
                        <div class="form-group">
                            <label>Server CPU warn %</label>
                            <input type="number" class="form-control" name="notify_cpu_warn" value="{{ old('notify_cpu_warn', $cpuWarn) }}" min="1" max="100">
                        </div>
                        <div class="form-group">
                            <label>Server RAM warn %</label>
                            <input type="number" class="form-control" name="notify_mem_warn" value="{{ old('notify_mem_warn', $memWarn) }}" min="1" max="100">
                        </div>
                        <div class="form-group">
                            <label>Server Disk warn %</label>
                            <input type="number" class="form-control" name="notify_disk_warn" value="{{ old('notify_disk_warn', $diskWarn) }}" min="1" max="100">
                        </div>
                        <div class="form-group">
                            <label>Node host CPU warn %</label>
                            <input type="number" class="form-control" name="notify_node_cpu_warn" value="{{ old('notify_node_cpu_warn', $nodeCpuWarn) }}" min="1" max="100">
                        </div>
                        <div class="form-group">
                            <label>Node host RAM warn %</label>
                            <input type="number" class="form-control" name="notify_node_mem_warn" value="{{ old('notify_node_mem_warn', $nodeMemWarn) }}" min="1" max="100">
                        </div>
                        <div class="form-group">
                            <label>Alert cooldown (seconds)</label>
                            <input type="number" class="form-control" name="notify_cooldown" value="{{ old('notify_cooldown', $cooldown) }}" min="60">
                            <p class="text-muted"><small>Same alert iske baad repeat nahi hoga (anti-spam).</small></p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Watchdog settings + discord --}}
            <div class="col-md-6">
                <div class="box box-danger">
                    <div class="box-header with-border"><h3 class="box-title">Watchdog Cutoff Settings</h3></div>
                    <div class="box-body">
                        <div class="form-group">
                            <label>Max crashes before suspend</label>
                            <input type="number" class="form-control" name="watchdog_max_restarts" value="{{ old('watchdog_max_restarts', $wdMaxRestarts) }}" min="1" max="10">
                        </div>
                        <div class="form-group">
                            <label>Crash window (seconds)</label>
                            <input type="number" class="form-control" name="watchdog_restart_window" value="{{ old('watchdog_restart_window', $wdRestartWindow) }}" min="60">
                        </div>
                        <div class="form-group">
                            <label>CPU overload threshold %</label>
                            <input type="number" class="form-control" name="watchdog_cpu_threshold" value="{{ old('watchdog_cpu_threshold', $wdCpuThreshold) }}" min="1" max="1000">
                        </div>
                        <div class="form-group">
                            <label>CPU sustained minutes</label>
                            <input type="number" class="form-control" name="watchdog_cpu_minutes" value="{{ old('watchdog_cpu_minutes', $wdCpuMinutes) }}" min="1" max="60">
                        </div>
                        <div class="form-group">
                            <label>OOM crashes before suspend</label>
                            <input type="number" class="form-control" name="watchdog_oom_limit" value="{{ old('watchdog_oom_limit', $wdOomLimit) }}" min="1" max="10">
                        </div>
                        <div class="form-group">
                            <label>Disk warn %</label>
                            <input type="number" class="form-control" name="watchdog_disk_warn" value="{{ old('watchdog_disk_warn', $wdDiskWarn) }}" min="1" max="100">
                        </div>
                    </div>
                </div>

                <div class="box box-success">
                    <div class="box-header with-border"><h3 class="box-title">Discord Webhook</h3></div>
                    <div class="box-body">
                        <div class="form-group">
                            <label>Webhook URL</label>
                            <input type="text" class="form-control" name="discord_webhook" value="{{ old('discord_webhook', $discordWebhook) }}" placeholder="https://discord.com/api/webhooks/...">
                            <p class="text-muted"><small>Channel → Edit → Integrations → Webhooks → New → Copy URL. Empty = notifications off.</small></p>
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-sm btn-primary">💾 Save All Settings</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- Test discord (separate form) --}}
    <div class="row">
        <div class="col-md-6 col-md-offset-6">
            <form action="{{ route('admin.zenith.testDiscord') }}" method="POST">
                @csrf
                <button class="btn btn-sm btn-info">📨 Send Test Message to Discord</button>
            </form>
        </div>
    </div>
@endsection
