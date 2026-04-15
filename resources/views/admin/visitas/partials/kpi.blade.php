@if(!empty($kpis))
<div class="p-6 bg-white rounded-lg shadow mt-4 mb-6">
    <h2 class="text-lg font-semibold mb-4 text-gray-800 flex items-center">
        <svg class="w-5 h-5 mr-2 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
            <path d="M2 11a1 1 0 011-1h3a1 1 0 010 2H3a1 1 0 01-1-1zm6-5a1 1 0 011-1h9a1 1 0 010 2H9a1 1 0 01-1-1zm-4 10a1 1 0 100 2h13a1 1 0 100-2H4zm0-5a1 1 0 100 2h13a1 1 0 100-2H4z" />
        </svg>
        Indicadores KPI
    </h2>

    {{-- Contenedor de tarjetas KPI (solo PREG_06_XX) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
        @foreach($kpis as $kpi)
            @if(str_starts_with($kpi['codigo_pregunta'], 'PREG_06_'))
            <div class="border rounded-xl p-4 shadow-sm bg-gray-50">
                <h6 class="text-base font-semibold text-gray-800 mb-2">
                    {{ $kpis_nombres[$kpi['codigo_pregunta']] ?? $kpi['codigo_pregunta'] }}
                </h6>
                <p class="text-sm text-gray-700">
                    <span class="font-medium">Valor:</span>
                    <span class="font-semibold text-blue-600">
                        {{ $kpi['valor'] ?? 'N/A' }}
                    </span>
                </p>
                @php
                $var = $kpi['variacion'] ?? null;
                @endphp
                @if($var !== null)
                <p class="text-sm text-gray-700">
                    <span class="font-medium">Variación:</span>
                    <span class="{{ (float)$var < 0 ? 'text-red-600' : 'text-green-600' }}">
                        {{ $var }}
                    </span>
                </p>
                @endif
            </div>
            @endif
        @endforeach
    </div>

    {{-- Observaciones KPI (OBS_06_XX) --}}
    @php
    $observaciones = collect($kpis)->filter(function($kpi) {
        return str_starts_with($kpi['codigo_pregunta'], 'OBS_06_');
    })->all();
    @endphp

    @if(!empty($observaciones))
    <div class="mt-6">
        <h6 class="text-sm font-medium text-gray-800 mb-3">Observaciones KPI</h6>
        <div class="space-y-3">
            @foreach($observaciones as $obs)
            <div class="border rounded-md p-3 bg-blue-50 border-blue-200">
                <p class="text-sm text-blue-800">
                    {{ $obs['valor'] ?? 'Sin observación' }}
                </p>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endif