<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    Storage::fake();
    $this->est = crearEstablecimiento();
    $this->base = "/api/v1/establecimientos/{$this->est->id}";
    Sanctum::actingAs(duenoDe($this->est));
    crearLote($this->est, 'BUB', false, 'Búfalas Murrah');
});

function planilla(string $csv): UploadedFile
{
    return UploadedFile::fake()->createWithContent('stock.csv', $csv);
}

it('sube CSV, valida por fila, corrige/descarta y confirma: un evento hacienda.alta por fila (idempotente)', function () {
    $csv = "especie;sexo;categoria;raza;caravana;rfid;tatuaje;fecha_nacimiento;lote\n"
        ."BUB;H;Búfala;Murrah;0142;032 000123456789;U-0142;20/09/2020;Búfalas Murrah\n"
        ."bubalino;hembra;BUB_BUBILLA;;0143;;;;búfalas murrah\n"
        ."BUB;M;Búfala;;0144;;;;\n"                        // categoría de otro sexo
        ."BOV;H;Vaca;Braford;0142;;;;\n";                   // caravana repetida en el archivo

    $imp = $this->postJson("{$this->base}/importaciones", ['archivo' => planilla($csv)])->assertStatus(202)->json('data.id');
    $this->getJson("{$this->base}/importaciones/{$imp}")->assertOk()
        ->assertJsonPath('data.estado', 'con_errores')->assertJsonPath('data.filas_total', 4)
        ->assertJsonPath('data.filas_validas', 2)->assertJsonPath('data.filas_error', 2);

    $errores = $this->getJson("{$this->base}/importaciones/{$imp}/filas?con_errores=1")->assertOk()->json('data');
    expect(array_column($errores, 'numero'))->toBe([3, 4])->and($errores[0]['errores'][0]['campo'])->toBe('categoria');
    $this->getJson("{$this->base}/importaciones/{$imp}/filas")->assertJsonPath('data.0.datos.categoria', 'BUB_BUFALA')
        ->assertJsonPath('data.0.datos.fecha_nacimiento', '2020-09-20');

    $this->patchJson("{$this->base}/importaciones/{$imp}/filas/3", ['datos' => ['especie' => 'BUB', 'sexo' => 'M', 'categoria' => 'Bubillo', 'castrado' => 'si', 'caravana' => '0144']])
        ->assertOk()->assertJsonPath('data.errores', []);
    $this->patchJson("{$this->base}/importaciones/{$imp}/filas/4", ['accion' => 'descartar'])->assertOk();
    $this->getJson("{$this->base}/importaciones/{$imp}")->assertJsonPath('data.estado', 'validada')->assertJsonPath('data.filas_validas', 3);

    $this->postJson("{$this->base}/importaciones/{$imp}/confirmar")->assertStatus(202);
    $this->getJson("{$this->base}/importaciones/{$imp}")->assertJsonPath('data.estado', 'confirmada');
    expect(DB::table('eventos')->where('tipo', 'hacienda.alta')->where('origen', 'importacion')->count())->toBe(3)
        ->and(DB::table('existencias')->where('establecimiento_id', $this->est->id)->sum('cabezas'))->toBe(3)
        ->and(DB::table('eventos')->whereRaw("payload->>'motivo' = 'importacion'")->whereRaw("payload->>'importacion_id' = ?", [$imp])->count())->toBe(3);

    $this->postJson("{$this->base}/importaciones/{$imp}/confirmar")->assertStatus(202);
    expect(DB::table('eventos')->count())->toBe(3);
    $this->postJson("{$this->base}/importaciones/{$imp}/cancelar")->assertStatus(422);
});

it('plantilla descargable por especie y mapeo de columnas propias', function () {
    $this->get('/api/v1/importaciones/plantilla?especie=BUB')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertSee('especie,sexo,categoria', false);

    $csv = "Caravana,Cat,Sexo\nA-1,Bubilla,H\n";
    $imp = $this->postJson("{$this->base}/importaciones", ['archivo' => planilla($csv), 'especie_default' => 'BUB', 'mapeo' => ['Cat' => 'categoria']])->json('data.id');
    $this->getJson("{$this->base}/importaciones/{$imp}")->assertJsonPath('data.estado', 'validada');
});

it('cancelar; 422 sin archivo; 403 encargado; 404 otro establecimiento', function () {
    $imp = $this->postJson("{$this->base}/importaciones", ['archivo' => planilla("especie,sexo,categoria\nBUB,H,Bubilla\n")])->json('data.id');
    $this->postJson("{$this->base}/importaciones/{$imp}/cancelar")->assertOk()->assertJsonPath('data.estado', 'cancelada');
    $this->postJson("{$this->base}/importaciones", [])->assertStatus(422);

    Sanctum::actingAs(miembro($this->est, 'encargado'));
    $this->postJson("{$this->base}/importaciones", ['archivo' => planilla("a\n")])->assertForbidden();
    Sanctum::actingAs(duenoDe(crearEstablecimiento()));
    $this->getJson("{$this->base}/importaciones/{$imp}")->assertNotFound();
});

it('también lee Excel (xlsx)', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
    $w = new Writer;
    $w->openToFile($tmp);
    $w->addRow(Row::fromValues(['especie', 'sexo', 'categoria', 'caravana']));
    $w->addRow(Row::fromValues(['BUB', 'H', 'Bubilla', 'X-1']));
    $w->close();

    $imp = $this->postJson("{$this->base}/importaciones", ['archivo' => UploadedFile::fake()->createWithContent('stock.xlsx', file_get_contents($tmp))])->json('data.id');
    $this->getJson("{$this->base}/importaciones/{$imp}")->assertJsonPath('data.estado', 'validada')->assertJsonPath('data.filas_validas', 1);
});
