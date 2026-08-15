<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ReportController extends Controller
{
    public function export(Request $request) {
        $validator = Validator::make($request->all(), [
            'ean' => 'nullable|string|max:15',
            'descricao' => 'nullable|string|max:255',
            'farmacia' => 'nullable|string',
            'formato' => 'required|in:csv,excel',
            'priceType' => 'required|in:current,historical',
            'data-inicio' => 'nullable|date',
            'data-fim' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $ean = $request->query('ean');
        $descricao = $request->query('descricao');
        $farmacia = $request->query('farmacia');
        $formato = $request->query('formato');
        $priceType = $request->query('priceType');
        $dataInicio = $request->query('data-inicio');
        $dataFim = $request->query('data-fim');

        try {
            if ($priceType === 'current') {
                $query = DB::table('precos_atuais as p')
                    ->join('produtos as prod', 'p.produto_id', '=', 'prod.produto_id')
                    ->join('farmacias as f', 'p.farmacia_id', '=', 'f.farmacia_id')
                    ->leftJoin('informacoes_produtos as ip', function ($join) {
                        $join->on('prod.produto_id', '=', 'ip.produto_id')
                            ->on('p.farmacia_id', '=', 'ip.farmacia_id');
                    })
                    ->select([
                        'f.nome_farmacia',
                        'prod.descricao',
                        'prod.laboratorio',
                        'prod.EAN',
                        'p.preco',
                        'p.data'
                    ]);
            } else {
                $query = DB::table('precos as p')
                    ->join('produtos as prod', 'p.produto_id', '=', 'prod.produto_id')
                    ->join('farmacias as f', 'p.farmacia_id', '=', 'f.farmacia_id')
                    ->select([
                        'f.nome_farmacia',
                        'prod.descricao',
                        'prod.laboratorio',
                        'prod.EAN',
                        'p.preco',
                        'p.data'
                    ]);

                if ($dataInicio && $dataFim) {
                    $query->whereBetween('p.data', [$dataInicio, $dataFim]);
                } elseif ($dataInicio) {
                    $query->where('p.data', '>=', $dataInicio);
                } elseif ($dataFim) {
                    $query->where('p.data', '<=', $dataFim);
                }
            }

            // Apply filters
            if ($ean) {
                $query->where('prod.EAN', $ean);
            } elseif ($descricao) {
                $query->where('prod.descricao', 'like', '%' . $descricao . '%');
            } elseif ($farmacia) {
                $farmaciaIds = array_map('intval', preg_split('/[\s+,;]+/', $farmacia));
                $query->whereIn('f.farmacia_id', $farmaciaIds);
            }

            $query->orderBy('p.preco', 'asc');

            if ($formato === 'csv') {
                return $this->streamCSV($query);
            } else {
                return $this->streamExcel($query);
            }
        } catch (\Exception $e) {
            Log::error('Erro ao exportar dados', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Erro ao exportar dados'], 500);
        }
    }

    private function streamCSV($query) {
        $filename = 'relatorio_' . date('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, ['Farmácia', 'Descrição', 'Laboratório', 'EAN', 'Preço', 'Data']);

            $query->chunk(2000, function ($items) use ($handle) {
                foreach ($items as $item) {
                    $clean = fn($v) => preg_replace('/[\r\n]+/', ' ', trim($v));
                    fputcsv($handle, [
                        $clean($item->nome_farmacia),
                        $clean($item->descricao),
                        $clean($item->laboratorio ?? ''),
                        "\t" . $item->EAN,
                        number_format((float) $item->preco, 2, '.', ''),
                        date('d/m/Y', strtotime($item->data))
                    ]);
                }
                flush();
            });

            fclose($handle);
        }, 200, $headers);
    }

    private function streamExcel($query) {
        $filename = 'relatorio_' . date('Y-m-d_His') . '.xlsx';

        $headers = [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Relatório');

        // Header row
        $sheet->fromArray(['Farmácia', 'Descrição', 'Laboratório', 'EAN', 'Preço', 'Data'], null, 'A1');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);

        $row = 2;
        $query->chunk(2000, function ($items) use ($sheet, &$row) {
            foreach ($items as $item) {
                $sheet->setCellValue("A$row", $item->nome_farmacia);
                $sheet->setCellValue("B$row", $item->descricao);
                $sheet->setCellValue("C$row", $item->laboratorio ?? '');
                $sheet->setCellValueExplicit("D$row", $item->EAN, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet->setCellValue("E$row", (float) $item->preco);
                $sheet->setCellValue("F$row", date('d/m/Y', strtotime($item->data)));
                $row++;
            }
        });

        // Format price column as number
        $sheet->getStyle('E2:E' . ($row - 1))
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        return response()->stream(function () use ($writer, $spreadsheet) {
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, $headers);
    }
}
