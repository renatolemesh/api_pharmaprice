<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grupos de medicamentos, a partir da classe terapeutica da CMED.
 *
 * Responde duas perguntas: quais termos leigos uma classe carrega, e quais
 * classes um texto digitado alcanca.
 */
class GrupoCmed
{
    /**
     * Abaixo disto o nome da classe nao e consultado. "anti" e prefixo de
     * metade das 541 classes; com cinco letras ("antib", "analg") ja separa.
     */
    private const MINIMO_PARA_NOME = 5;

    private static ?array $mapa = null;

    /**
     * Termos leigos de uma classe: a uniao dos termos de cada prefixo do
     * codigo, do mais especifico para o mais geral.
     */
    public static function termosDe(string $codigo): ?string
    {
        self::$mapa ??= require database_path('data/cmed_termos.php');

        $termos = [];
        foreach (self::prefixos($codigo) as $prefixo) {
            foreach (explode(',', self::$mapa[$prefixo] ?? '') as $termo) {
                $termo = trim($termo);
                if ($termo !== '') {
                    $termos[$termo] = true;
                }
            }
        }

        return $termos ? implode(', ', array_keys($termos)) : null;
    }

    /**
     * Prefixos de um codigo, do mais especifico ao mais geral.
     *
     * "J07A1" => ["J7A1", "J7A", "J7", "J"]. O zero some porque a Anvisa
     * publica o mesmo nivel como "J7E1" numa linha e "J07A1" em outra.
     * Codigo fora do padrao ("FITO") vale so por ele mesmo.
     */
    public static function prefixos(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));

        if (!preg_match('/^([A-Z])0?(\d{1,2})([A-Z])?(\d)?$/', $codigo, $m)) {
            return [$codigo];
        }

        $letra = $m[1];
        $nivel2 = $letra . $m[2];
        $nivel3 = isset($m[3]) && $m[3] !== '' ? $nivel2 . $m[3] : null;
        $nivel4 = $nivel3 && isset($m[4]) && $m[4] !== '' ? $nivel3 . $m[4] : null;

        return array_values(array_filter([$nivel4, $nivel3, $nivel2, $letra]));
    }

    /**
     * Codigos das classes que um texto alcanca.
     *
     * Nos termos, casa por palavra inteira: "dor" encontra "dor de cabeca" mas
     * nao "dormir" - com LIKE, quem buscasse dor levaria remedio de insonia.
     * No nome oficial casa por inicio de palavra ("antibio" acha
     * "ANTIBIOTICOS"), e so a partir de cinco letras.
     *
     * Sao 541 linhas curtas; comparar em PHP custa menos de um milissegundo e
     * dispensa montar a mesma regra em SQL.
     *
     * @return string[]
     */
    public static function codigosPara(string $texto): array
    {
        $texto = self::normalizar($texto);
        if ($texto === '') {
            return [];
        }

        $padraoTermo = '/(?<![a-z0-9])' . preg_quote($texto, '/') . '(?![a-z0-9])/';
        $padraoNome = mb_strlen($texto) >= self::MINIMO_PARA_NOME
            ? '/(?<![a-z0-9])' . preg_quote($texto, '/') . '/'
            : null;

        $codigos = [];
        foreach (DB::table('cmed_classes')->get(['codigo', 'nome', 'termos']) as $classe) {
            if (preg_match($padraoTermo, self::normalizar((string) $classe->termos))
                || ($padraoNome && preg_match($padraoNome, self::normalizar($classe->nome)))) {
                $codigos[] = $classe->codigo;
            }
        }

        return $codigos;
    }

    /** Minusculas, sem acento, espacos simples: "Dor de Cabeça " => "dor de cabeca". */
    public static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($texto))));
    }
}
