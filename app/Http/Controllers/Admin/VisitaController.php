<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Google\Cloud\Storage\StorageClient; // AGREGAR ESTA LÍNEA AL INICIO
use Illuminate\Support\Facades\Log;
use Carbon\Carbon; // Asegúrate de tener Carbon importado


class VisitaController extends Controller
{
    protected $usuario;

    public function __construct()
    {
        $this->usuario = new Usuario();
    }

    /**
     * Mostrar detalle completo de una visita
     */
    public function show($id)
    {
        set_time_limit(120);
        try {
            $user = session('admin_user');

            // Obtener visita completa
            $visitaRaw = $this->usuario->getVisitaCompleta(
                $id,
                $user['rol'],
                $user['email']
            );

            if (!$visitaRaw) {
                abort(404, 'Visita no encontrada o sin permisos para verla.');
            }

            // Obtener KPIs de la visita (extraer de secciones si no existen como columna separada)
            $kpis = $visitaRaw['kpis'] ?? [];

            // Convertir KPIs a array de arrays si vienen como objetos
            $kpisArray = [];
            if (is_array($kpis)) {
                foreach ($kpis as $kpi) {
                    $kpiData = (array) $kpi;
                    // Normalizar código a mayúscula
                    if (isset($kpiData['codigo_pregunta'])) {
                        $kpiData['codigo_pregunta'] = strtoupper($kpiData['codigo_pregunta']);
                    }
                    $kpisArray[] = $kpiData;
                }
                $kpis = $kpisArray;
            }

            // Si no hay KPIs en columna separada, extraerlos de secciones (PREG_06_XX)
            if (empty($kpis) && isset($visitaRaw['secciones'])) {
                $kpis = [];
                foreach ($visitaRaw['secciones'] as $seccion) {
                    foreach ($seccion['preguntas'] as $pregunta) {
                        $codigo = strtoupper($pregunta['codigo_pregunta'] ?? '');
                        if (str_starts_with($codigo, 'PREG_06_')) {
                            $kpis[] = [
                                'codigo_pregunta' => $codigo,
                                'valor' => $pregunta['respuesta'] ?? $pregunta['valor'] ?? null,
                                'variacion' => $pregunta['variacion'] ?? null,
                                'observaciones' => $pregunta['observaciones'] ?? null
                            ];
                        }
                    }
                }
            }

            Log::info('KPIs extraídos para visita ' . $id . ': ' . count($kpis) . ' KPIs');

            // VALIDACIÓN DE ACCESO POR PAÍS
            if (!$this->validarAccesoPais($visitaRaw, $user)) {
                abort(403, 'No tiene permisos para ver visitas de este país.');
            }

            // Procesar datos para display
            $visita = $this->usuario->procesarDatosVisita($visitaRaw);

            // Convertir fechas a hora local (UTC-6 / America/El_Salvador)
            $visita['fecha_hora_inicio_local'] = Carbon::parse((string) ($visitaRaw['fecha_hora_inicio'] ?? ''))->setTimezone('America/El_Salvador')->format('Y-m-d H:i:s');
            $visita['fecha_hora_fin_local'] = $visitaRaw['fecha_hora_fin']
                ? Carbon::parse((string) $visitaRaw['fecha_hora_fin'])->setTimezone('America/El_Salvador')->format('Y-m-d H:i:s')
                : null;

            // Calcular puntuaciones
            $puntuaciones = $this->usuario->calcularPuntuaciones($visita);

            // Obtener textos de preguntas
            $textosPreguntas = $this->getTextosPreguntas();

            // Calcular resumen por área para visual-scoring
            $puntajesPorArea = $this->usuario->calcularPuntajesPorArea($visita);

            // Obtener nombres de KPIs para visualización
            $kpis_nombres = $textosPreguntas['kpis'] ?? [];

            // 📍 VALIDACIÓN DE DISTANCIA (reutiliza $visitaRaw ya obtenido, evita segunda consulta a BigQuery)
            $validacionDistancia = $this->usuario->validarDistanciaVisita($visitaRaw);

            return view('admin.visitas.show', compact(
                'visita',
                'puntuaciones',
                'textosPreguntas',
                'validacionDistancia',
                'puntajesPorArea',
                'kpis',
                'kpis_nombres'
            ));
        } catch (\Exception $e) {
            Log::error('Error al mostrar visita: ' . $e->getMessage());

            if ($e->getCode() === 404) {
                abort(404);
            }

            return redirect()->route('admin.dashboard')
                ->with('error', 'Error al cargar el detalle de la visita.');
        }
    }

    /**
     * Mostrar galería de imágenes de una visita
     */
    public function imagenes($id)
    {
        set_time_limit(120);
        try {
            $user = session('admin_user');

            $visitaRaw = $this->usuario->getVisitaCompleta(
                $id,
                $user['rol'],
                $user['email']
            );

            if (!$visitaRaw) {
                abort(404, 'Visita no encontrada o sin permisos para verla.');
            }

            if (!$this->validarAccesoPais($visitaRaw, $user)) {
                abort(403, 'No tiene permisos para ver imágenes de visitas de este país.');
            }

            // Cargar textos de preguntas para mostrar descripciones completas
            $estructura = $this->getTextosPreguntas();
            $textosPreguntasPlanos = collect($estructura)->flatMap(fn($seccion) => $seccion);

            // Inicializar Google Cloud Storage
            $storage = new StorageClient([
                'projectId' => env('BIGQUERY_PROJECT_ID', 'adoc-bi-dev'),
                'keyFilePath' => storage_path('app' . env('BIGQUERY_KEY_FILE', '/claves/adoc-bi-dev-debcb06854ae.json'))
            ]);
            $bucketName = env('GOOGLE_CLOUD_STORAGE_BUCKET', 'adoc-bi-dev-control-visitas-lz');
            $bucket = $storage->bucket($bucketName);

            $imagenesAgrupadas = [];

            foreach ($visitaRaw['secciones'] as $seccion) {
                $nombreSeccion = $seccion['nombre_seccion'] ?? 'Sin Nombre';
                $codigoSeccion = $seccion['codigo_seccion'] ?? null;

                $bloque = [
                    'nombre_seccion' => ucfirst($nombreSeccion),
                    'codigo_seccion' => $codigoSeccion,
                    'preguntas' => []
                ];

                foreach ($seccion['preguntas'] as $pregunta) {
                    $codigo = strtoupper($pregunta['codigo_pregunta']);
                    $observaciones = $pregunta['observaciones'] ?? $pregunta['respuesta'] ?? null;
                    $urls = $pregunta['imagenes'] ?? [];

                    $imagenes = [];

                    foreach ($urls as $urlOriginal) {
                        try {
                            $fileName = basename(parse_url($urlOriginal, PHP_URL_PATH));
                            $object = $bucket->object('observaciones/' . $fileName);

                            if ($object->exists()) {
                                $signedUrl = $object->signedUrl(new \DateTime('+2 hours'));
                            } else {
                                $objectAlt = $bucket->object($fileName);
                                $signedUrl = $objectAlt->exists() ? $objectAlt->signedUrl(new \DateTime('+2 hours')) : $urlOriginal;
                            }

                            $imagenes[] = [
                                'url' => $signedUrl,
                                'url_original' => $urlOriginal
                            ];
                        } catch (\Exception $e) {
                            Log::error("Error generando URL firmada: " . $e->getMessage());
                            $imagenes[] = [
                                'url' => $urlOriginal,
                                'url_original' => $urlOriginal
                            ];
                        }
                    }

                    $bloque['preguntas'][] = [
                        'codigo_pregunta' => $codigo,
                        'texto_pregunta' => $textosPreguntasPlanos[$codigo] ?? $codigo,
                        'observaciones' => $observaciones,
                        'imagenes' => $imagenes
                    ];
                }

                $imagenesAgrupadas[] = $bloque;
            }

            // Información básica de la visita
            $infoVisita = [
                'id' => $visitaRaw['id'],
                'tienda' => $visitaRaw['tienda'] ?? 'N/A',
                'pais' => $visitaRaw['pais'] ?? 'N/A',
                'zona' => $visitaRaw['zona'] ?? 'N/A',
                'fecha' => $visitaRaw['fecha_hora_inicio'],
                'evaluador' => $visitaRaw['lider_zona'] ?? $visitaRaw['correo_realizo']
            ];

            return view('admin.visitas.imagenes', [
                'infoVisita' => $infoVisita ?? [],
                'imagenes' => collect($imagenesAgrupadas)->flatMap(fn($b) => collect($b['preguntas'])->flatMap(fn($p) => $p['imagenes'] ?? [])),
                'imagenesAgrupadas' => collect($imagenesAgrupadas),
            ]);
        } catch (\Exception $e) {
            Log::error('Error al mostrar imágenes: ' . $e->getMessage());
            return redirect()->route('admin.dashboard')
                ->with('error', 'Error al cargar las imágenes de la visita: ' . $e->getMessage());
        }
    }

    /**
     * API para obtener datos de visita (AJAX)
     */
    public function getVisitaData($id)
    {
        set_time_limit(120);
        try {
            $user = session('admin_user');

            $visitaRaw = $this->usuario->getVisitaCompleta(
                $id,
                $user['rol'],
                $user['email']
            );

            if (!$visitaRaw) {
                return response()->json([
                    'success' => false,
                    'error' => 'Visita no encontrada'
                ], 404);
            }

            // Validar acceso por país
            if (!$this->validarAccesoPais($visitaRaw, $user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Sin acceso a visitas de este país'
                ], 403);
            }

            $visita = $this->usuario->procesarDatosVisita($visitaRaw);
            $puntuaciones = $this->usuario->calcularPuntuaciones($visita);
            $puntajesPorArea = $this->usuario->calcularPuntajesPorArea($visita);

            // VALIDACIÓN DE DISTANCIA PARA API (reutiliza $visitaRaw, evita segunda consulta a BigQuery)
            $validacionDistancia = $this->usuario->validarDistanciaVisita($visitaRaw);

            return response()->json([
                'success' => true,
                'data' => $visita,
                'puntuaciones' => $puntuaciones,
                'validacion_distancia' => $validacionDistancia
            ]);
        } catch (\Exception $e) {
            Log::error('Error en getVisitaData API: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener textos de las preguntas para display
     */
    private function getTextosPreguntas()
    {
        $estructura = [
            'operaciones' => [
                'PREG_02_01' => 'Pintura de tienda en buen estado. Interior/Exterior.',
                'PREG_02_02' => 'Vitrinas de tiendas limpias, con iluminación y acrílicos en buen estado.',
                'PREG_02_03' => 'Exhibición de producto en vitrina según estándares.',
                'PREG_02_04' => 'Sala de ventas limpia, ordenada y con iluminación en buen estado.',
                'PREG_02_05' => 'Aires acondicionados/ventiladores y escaleras en buen estado.',
                'PREG_02_06' => 'Repisas, mesas y muebles de exhibición limpios y en buen estado.',
                'PREG_02_07' => 'Mueble de caja limpio, ordenado y en buen estado',
                'PREG_02_08' => 'Equipo funcionando (radio, tel., cel., conteo de clientes, eq. de computo).',
                'PREG_02_09' => 'Utilización de la radio ADOC para ambientar la tienda.',
                'PREG_02_10' => 'Bodega limpia, con iluminación en buen estado y ordenada según manual.',
                'PREG_02_11' => 'Accesorios de limpieza ordenados y ubicados en el lugar adecuado.',
                'PREG_02_12' => 'Área de comida limpia y articulos personales ordenados en su área.',
                'PREG_02_13' => 'Baño limpio y ordenado',
                'PREG_02_14' => 'La tienda cuenta con suficientes sillas o bancos en buen estado (limpios y lavados) para que los clientes se prueben los zapatos (según planograma o layout). NOTA: Si los sillones están sucios deben mandarse a lavar.',
                'PREG_02_15' => 'Las cajas alzadoras de zapatos se usan en las exhibiciones.',
                'PREG_02_16' => 'No se usa cinta adhesiva (tape) en ningun lugar de la tienda.',
                'PREG_02_17' => 'No hay muebles dañados, rotos o qubrados en la tienda.',
                'PREG_02_18' => 'El area de caja está ordenada y conforme a los estándares autorizados y en servicio.',
                'PREG_02_19' => 'Se ofrecen accesorios a los clientes en cada visita o compra.',
                'PREG_02_20' => 'Todas las luces de los muebles de pared y mesa son funcionales y emiten una luz amarilla intensa (3500-4000 lúmenes).',
                'PREG_02_21' => 'Las pantallas de la vitrina estan posicionadas a 90 grados (de forma vertical).',
                'PREG_02_22' => 'Los azulejos, la fórmica y el piso no están dañados en ningún lugar de la tienda.',
            ],
            'administracion' => [
                'PREG_03_01' => 'Cuenta de orden al día.',
                'PREG_03_02' => 'Documentos de transferencias y envíos ingresados al sistema al día',
                'PREG_03_03' => 'Remesas de efectivo al día e ingresados al sistema',
                'PREG_03_04' => 'Libro de cuadre de efectivo y caja chica al día',
                'PREG_03_05' => 'Libro de horarios al día y firmados por los empleados',
                'PREG_03_06' => 'Conteo efectuados según lineamientos establecidos.',
                'PREG_03_07' => 'Files actualizados.',
            ],
            'producto' => [
                'PREG_04_01' => 'Nuevos estilos exhibidos en sala de venta.',
                'PREG_04_02' => 'Artículos exhibidos con su etiqueta y precio correcto. Nota: Si un zapato llega dañado de fábrica reportarlo de inmediato y retírelo del piso de venta.',
                'PREG_04_03' => 'Cambios de precio realizado, firmado y archivado. Nota: Es prohibido colocar otro precio que no sea el oficial.',
                'PREG_04_04' => 'Promociones actualizadas y compartidas con todo el personal.',
                'PREG_04_05' => 'Implementación de planogramas(Producto, POP, Manuales).',
                'PREG_04_06' => 'En las exhibiciones están todos los estilos disponibles en la tienda representados por talla (sin ningún zapato dañado o sucio).',
                'PREG_04_07' => 'Todas las sandalias en exhibidores y/o mesas usan modeladores acrílicos.',
                'PREG_04_08' => 'Todas las sandalias y zapatos abiertos tienen un acrílico.',
                'PREG_04_09' => 'Todas las carteras tienen un alzador en las exhibiciones.',
            ],
            'personal' => [
                'PREG_05_01' => 'Personal con imagen presentable, con su respectivo uniforme según política.',
                'PREG_05_02' => 'Amabilidad en el recibimiento de los clientes.',
                'PREG_05_03' => 'Disponibilidad del personal para ayudar durante el recorrido, selección y prueba de calzado.',
                'PREG_05_04' => 'Nuestros ADOCKERS ofrecen ayuda a todos los clientes.',
                'PREG_05_05' => 'Nuestros ADOCKERS ofrecen encontrar la talla que el cliente pide y si no hay talla, ofrecen alternativas.',
                'PREG_05_06' => 'Nuestros ADOCKERS ofrecen medir el pie de los niños.',
                'PREG_05_07' => 'Se ofrecen diferentes zapatos para que ajuste el pie correctamente cuando hay niños.',
                'PREG_05_08' => 'Nuestros ADOCKERS elogian a los clientes por su elección de producto.',
                'PREG_05_09' => 'Nuestros clientes son atendidos rápidamente en caja.',
                'PREG_05_10' => '¿Han realizado los cursos de Academia ADOC?',
                'PREG_05_11' => '¿Adockers hacen uso de la APP ADOCKY cuando atienden a los clientes en el piso de venta?',
                'PREG_05_12' => 'Adockers hacen uso de la APP ADOCKY para realizar la representación de inventario.',
            ],
            'kpis' => [
                'PREG_06_01' => 'Venta',
                'PREG_06_02' => 'Margen',
                'PREG_06_03' => 'Conversión',
                'PREG_06_04' => 'UPT',
                'PREG_06_05' => 'DPT',
                'PREG_06_06' => 'NPS',
            ]
        ];
        // Aplanar si necesitás acceder por código directamente sin saber la sección
        $textosPlanos = [];

        foreach ($estructura as $seccion => $preguntas) {
            foreach ($preguntas as $codigo => $texto) {
                $textosPlanos[$codigo] = $texto;
            }
        }

        return $estructura;
    }

    /**
     * Validar si el usuario tiene acceso a visitas del pa�1�7�1�7s de la visita
     */
    private function validarAccesoPais($visitaData, $userData)
    {
        // Admin y evaluador normal tienen acceso completo
        if (in_array($userData['rol'], ['admin', 'evaluador'])) {
            return true;
        }

        // Para evaluador_pais, verificar pa�1�7�1�7s espec�1�7�1�7fico
        if ($userData['rol'] === 'evaluador_pais') {
            $paisVisita = $visitaData['pais'] ?? null;
            $paisPermitido = $userData['pais_acceso'] ?? null;

            // Log del intento de acceso para auditor�1�7�1�7a
            Log::info('Validación acceso por país', [
                'usuario' => $userData['email'],
                'rol' => $userData['rol'],
                'pais_visita' => $paisVisita,
                'pais_permitido' => $paisPermitido,
                'visita_id' => $visitaData['id'] ?? 'N/A'
            ]);

            // Verificar acceso
            if ($paisPermitido === 'ALL') {
                return true;
            }

            if ($paisPermitido === $paisVisita) {
                return true;
            }

            // Log de acceso denegado
            Log::warning('Acceso denegado por país', [
                'usuario' => $userData['email'],
                'pais_solicitado' => $paisVisita,
                'pais_permitido' => $paisPermitido,
                'visita_id' => $visitaData['id'] ?? 'N/A'
            ]);

            return false;
        }

        return false;
    }
    
        /**
     * Mostrar detalle de una sección específica de la visita
     */
    public function detalleArea($id, $seccion)
    {
        $user = session('admin_user');

        // 1️⃣ Obtener visita completa
        $visitaRaw = $this->usuario->getVisitaCompleta(
            $id,
            $user['rol'],
            $user['email']
        );

        // 2️⃣ Validación de acceso
        if (!$visitaRaw || !$this->validarAccesoPais($visitaRaw, $user)) {
            abort(403, 'No tiene permisos para ver esta área.');
        }

        // 3️⃣ Procesar visita
        $visita = $this->usuario->procesarDatosVisita($visitaRaw);

        // 4️⃣ Obtener textos de preguntas
        $textosPreguntas = $this->getTextosPreguntas();

        // 5️⃣ Filtrar sección deseada por nombre
        $secciones = $visita['secciones'] ?? [];
        Log::info('[detalleArea] Áreas disponibles en la visita', [
            'id' => $id,
            'seccion_param' => $seccion,
            'secciones_disponibles' => array_map(function($area) {
                return $area['nombre_seccion'] ?? '';
            }, $secciones)
        ]);
        $areaSeleccionada = collect($secciones)->firstWhere(function($area) use ($seccion) {
            // Normaliza para evitar problemas de mayúsculas/minúsculas y espacios
            $nombre = strtolower(str_replace(' ', '', $area['nombre_seccion'] ?? ''));
            $buscado = strtolower(str_replace(' ', '', $seccion));
            return $nombre === $buscado;
        });

        if (!$areaSeleccionada) {
            $seccionesDisponibles = array_map(function($area) {
                return $area['nombre_seccion'] ?? '';
            }, $secciones);
            Log::error("[detalleArea] Área no encontrada: {$seccion} | Secciones disponibles: " . implode(', ', $seccionesDisponibles), [
                'id' => $id,
                'seccion_param' => $seccion,
                'secciones_disponibles' => $seccionesDisponibles
            ]);
            abort(404, 'Área no encontrada');
        }

        return view('admin.visitas.detalle_area', [
            'area' => $areaSeleccionada,
            'titulo' => ucfirst($seccion),
            'textosPreguntas' => $textosPreguntas,
            'idVisita' => $id
        ]);
    }
}
