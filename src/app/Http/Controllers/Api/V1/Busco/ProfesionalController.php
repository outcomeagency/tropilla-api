<?php

namespace App\Http\Controllers\Api\V1\Busco;

use App\Casts\PgTextArray;
use App\Domain\Establecimientos\Models\Establecimiento;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Rules\Reglas;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Perfil de profesional (TPA-58): matrícula **declarada** (no verificada: así se muestra), zona e idiomas. «Trabaja con X» solo si el
 * productor lo autoriza (`POST …/profesionales`) y su perfil está publicado.
 */
class ProfesionalController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $p = DB::table('profesionales')->where('usuario_id', $request->user()->id)->first() ?? throw ApiException::noEncontrado(__('Todavía no tenés perfil profesional.'));

        return response()->json(['data' => self::profesional($p)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'slug' => ['required', 'regex:/^[a-z0-9-]{3,60}$/', Rule::unique('profesionales', 'slug')->ignore($u->id, 'usuario_id')],
            'nombre_publico' => ['required', 'string', 'min:3', 'max:120'], 'profesion' => ['required', 'in:veterinario,ing_agronomo,asesor,otro'],
            'matricula_declarada' => ['sometimes', 'nullable', 'string', 'max:60'], 'especialidades' => ['sometimes', 'array', 'max:20'], 'especialidades.*' => ['string', 'max:60'],
            'zonas' => ['sometimes', 'array'], 'zonas.provincias' => ['sometimes', 'array'], 'zonas.provincias.*' => ['string', 'size:2'],
            'zonas.departamentos' => ['sometimes', 'array'], 'zonas.departamentos.*' => ['string', 'max:5'],
            'idiomas' => ['sometimes', 'array'], 'idiomas.*' => ['in:es,en,pt'], 'bio' => ['sometimes', 'nullable', 'string', 'max:2000'], 'publico' => ['sometimes', 'boolean'],
        ]);
        if (($d['publico'] ?? false) && ! $u->email_verificado_en) {
            throw new ApiException(403, 'email_no_verificado', __('Verificá tu email para publicar tu perfil.'));
        }
        $arr = fn (array $v) => '{'.implode(',', array_map(fn ($x) => '"'.str_replace('"', '', $x).'"', array_values(array_unique($v)))).'}';
        DB::table('profesionales')->updateOrInsert(['usuario_id' => $u->id], [
            'slug' => $d['slug'], 'nombre_publico' => $d['nombre_publico'], 'profesion' => $d['profesion'], 'matricula_declarada' => $d['matricula_declarada'] ?? null,
            'especialidades' => $arr($d['especialidades'] ?? []), 'zonas' => json_encode((object) ($d['zonas'] ?? [])), 'idiomas' => $arr($d['idiomas'] ?? ['es']),
            'bio' => $d['bio'] ?? null, 'publico' => $d['publico'] ?? false, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return $this->show($request);
    }

    public function publico(Request $request, string $slug): JsonResponse
    {
        $p = DB::table('profesionales')->where('slug', $slug)->where('publico', true)->first() ?? throw ApiException::noEncontrado();
        $ests = DB::table('profesional_establecimientos as pe')->join('perfiles_publicos as pp', fn ($j) => $j->on('pp.establecimiento_id', '=', 'pe.establecimiento_id')->where('pp.publicado', true))
            ->join('establecimientos as e', 'e.id', '=', 'pe.establecimiento_id')->where('pe.profesional_id', $p->usuario_id)->where('pe.visible_en_perfil', true)
            ->whereNull('pe.revocado_en')->orderBy('e.nombre')->get(['pp.slug', 'e.nombre']);

        return response()->json(['data' => [
            'slug' => $p->slug, 'nombre' => $p->nombre_publico, 'profesion' => $p->profesion, 'matricula_declarada' => $p->matricula_declarada,
            'especialidades' => PgTextArray::parse($p->especialidades), 'zonas' => (object) json_decode($p->zonas, true), 'idiomas' => PgTextArray::parse($p->idiomas),
            'bio' => $p->bio, 'foto_url' => null, 'establecimientos' => $ests->map(fn ($e) => ['slug' => $e->slug, 'nombre' => $e->nombre])->all(),
            'leyenda' => Leyenda::texto(Leyenda::locale($request->query('locale'))).' '.__('Matrícula declarada por el profesional, no verificada por TROPILLA.'),
        ]], 200, ['Cache-Control' => 'public, max-age=60'], JSON_UNESCAPED_UNICODE);
    }

    /** Autorizar a un profesional a mostrarse vinculado (o dejar de mostrarlo). */
    public function autorizar(Request $request, Establecimiento $establecimiento): JsonResponse
    {
        Gate::authorize('usuarios.gestionar', $establecimiento);
        $d = $request->validate(['usuario_id' => ['required', Reglas::UUID, Rule::exists('profesionales', 'usuario_id')], 'visible_en_perfil' => ['sometimes', 'boolean']]);
        DB::table('profesional_establecimientos')->updateOrInsert(['profesional_id' => $d['usuario_id'], 'establecimiento_id' => $establecimiento->id],
            ['autorizado_por' => $request->user()->id, 'visible_en_perfil' => $d['visible_en_perfil'] ?? true, 'revocado_en' => null, 'created_at' => now()]);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> Profesional */
    public static function profesional(object $p): array
    {
        return ['slug' => $p->slug, 'nombre_publico' => $p->nombre_publico, 'profesion' => $p->profesion, 'matricula_declarada' => $p->matricula_declarada,
            'especialidades' => PgTextArray::parse($p->especialidades), 'zonas' => (object) json_decode($p->zonas, true), 'idiomas' => PgTextArray::parse($p->idiomas),
            'bio' => $p->bio, 'publico' => (bool) $p->publico];
    }
}
