<?php

namespace App\Http\Controllers\Api\V1\Publico;

use App\Domain\Integridad\Verificacion;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Leyenda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Verificación pública (TPA-41): `/v/{huella}` y «verificar un archivo» sin guardarlo. */
class VerificacionController extends Controller
{
    public function show(Request $request, string $huella): JsonResponse
    {
        $v = $this->buscar($huella, Leyenda::locale($request->query('locale')));

        return response()->json(['data' => $v], 200, ['Cache-Control' => 'public, max-age=60']);
    }

    /** prueba.json: todo lo necesario para verificar sin confiar en TROPILLA (§6.4). */
    public function prueba(Request $request, string $huella): JsonResponse
    {
        $v = $this->buscar($huella, 'es');
        $i = $v['integridad'];

        return response()->json([
            'version' => 'TROPILLA-CJ/1', 'huella' => $i['huella'], 'algoritmo' => 'sha256(utf8(canonico(sobre)))',
            'eslabon_anterior' => $i['eslabon_anterior'], 'eslabon' => $i['eslabon'], 'formula_eslabon' => 'sha256(eslabon_anterior + "\n" + huella)',
            'seq' => $i['seq'], 'registrado_en' => $i['registrado_en'], 'anclaje' => $i['anclaje'], 'prueba_merkle' => $i['prueba'],
            'merkle' => 'RFC 6962 (hoja 0x00 || eslabón, nodo 0x01 || izq || der)', 'sobre' => $v['sobre'],
        ], 200, ['Content-Disposition' => "attachment; filename=\"prueba-{$huella}.json\""]);
    }

    /** Recibo del anclaje (`.ots` más reciente) para verificar con el cliente `ots` sin confiar en TROPILLA (TPA-40). */
    public function recibo(string $anclaje): Response
    {
        $a = DB::table('anclajes')->where('id', strtolower($anclaje))->whereNotNull('recibo_clave')->first() ?? throw ApiException::noEncontrado();
        $bytes = Storage::get($a->recibo_clave) ?? throw ApiException::noEncontrado();

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"tropilla-{$a->raiz}.ots\"", 'Cache-Control' => 'public, max-age=300']);
    }

    public function archivo(Request $request, string $sha256): JsonResponse
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw ApiException::validacion(__('SHA-256 inválido.'), ['sha256' => ['64 hex en minúscula.']]);
        }

        return response()->json(['data' => Verificacion::deArchivo($sha256, Leyenda::locale($request->query('locale')))]);
    }

    /** Igual, subiendo el archivo (multipart, ≤ 25 MB). **No se guarda**: solo se calcula su SHA-256. */
    public function subirArchivo(Request $request): JsonResponse
    {
        $request->validate(['archivo' => ['required', 'file', 'max:25600']]);
        $sha = hash_file('sha256', $request->file('archivo')->getRealPath());

        return response()->json(['data' => Verificacion::deArchivo($sha, Leyenda::locale($request->query('locale')))]);
    }

    /** @return array<string, mixed> */
    private function buscar(string $huella, string $locale): array
    {
        if (! preg_match('/^[0-9a-f]{64}$/', $huella)) {
            throw ApiException::noEncontrado();
        }

        return Verificacion::deHuella($huella, $locale) ?? throw ApiException::noEncontrado();
    }
}
