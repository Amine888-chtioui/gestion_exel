<?php

namespace App\Http\Controllers;

use App\Models\ProductionStop;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ImportController extends Controller
{
    /**
     * Import production stops data from an Excel file
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
            'delete_existing' => 'boolean'
        ]);

        try {
            // Start a transaction
            DB::beginTransaction();
            
            // Clear existing data if requested
            if ($request->input('delete_existing', false)) {
                ProductionStop::truncate();
            }

            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Skip the header row
            $headerRow = array_shift($rows);
            
            // Count processed and skipped rows
            $processed = 0;
            $skipped = 0;

            // Map to track the relationship between machine names and groups
            $machineGroups = [];

            foreach ($rows as $index => $row) {
                // Skip empty rows
                if (empty($row[0]) || !array_filter($row)) {
                    $skipped++;
                    continue;
                }
                
                // Extract machine name and machine group from the data
                $machineName = null;
                $machineGroup = null;
                
                // Look for machine information (patterns identified from the file)
                foreach ($row as $cellIndex => $cell) {
                    if (is_string($cell)) {
                        // Try to identify ALPHA machine (like ALPHA 63)
                        if (preg_match('/^ALPHA\s+\d+$/i', $cell)) {
                            $machineName = $cell;
                        } 
                        // Try to identify Komax machine group (like Komax Alpha 355)
                        elseif (preg_match('/Komax\s+Alpha\s+\d+/i', $cell)) {
                            $machineGroup = $cell;
                            
                            // Update our mapping of machine names to groups
                            if ($machineName) {
                                $machineGroups[$machineName] = $machineGroup;
                            }
                        }
                    }
                }
                
                // If we couldn't find a machine name directly, try to infer it from the row
                if (!$machineName) {
                    // Check if we have something like "ALPHA 63" in the first few columns
                    for ($i = 0; $i < min(5, count($row)); $i++) {
                        if (isset($row[$i]) && is_string($row[$i]) && 
                            preg_match('/ALPHA\s+\d+/i', $row[$i], $matches)) {
                            $machineName = $matches[0];
                            break;
                        }
                    }
                }
                
                // Map column indices to our expected structure
                // Adjust these indices based on your actual Excel file structure
                $fromDateIndex = $this->findColumnIndex($headerRow, ['from date', 'from_date', 'date']);
                $toDateIndex = $this->findColumnIndex($headerRow, ['to date', 'to_date', 'end date']);
                $moKeyIndex = $this->findColumnIndex($headerRow, ['mo key', 'mo_key', 'maintenance object']);
                $wsKeyIndex = $this->findColumnIndex($headerRow, ['ws key', 'ws_key', 'workstation']);
                $stopTIndex = $this->findColumnIndex($headerRow, ['stop t', 'stop_t', 'stop type']);
                $woKeyIndex = $this->findColumnIndex($headerRow, ['wo key', 'wo_key', 'work order key']);
                $woNameIndex = $this->findColumnIndex($headerRow, ['wo name', 'wo_name', 'work order name']);
                $code1KeyIndex = $this->findColumnIndex($headerRow, ['code1 key', 'code1_key', 'type']);
                $code2KeyIndex = $this->findColumnIndex($headerRow, ['code2 key', 'code2_key', 'cause']);
                $code3KeyIndex = $this->findColumnIndex($headerRow, ['code3 key', 'code3_key', 'component']);
                $stopDurationIndex = $this->findColumnIndex($headerRow, ['stop duration', 'stop_duration', 'duration']);
                
                // Attempt to extract data from the row
                try {
                    // Create a production stop record
                    $productionStop = new ProductionStop();
                    $productionStop->from_date = $this->parseDate($row[$fromDateIndex] ?? null);
                    $productionStop->to_date = $this->parseDate($row[$toDateIndex] ?? null);
                    $productionStop->mo_key = $row[$moKeyIndex] ?? null;
                    $productionStop->ws_key = $row[$wsKeyIndex] ?? null;
                    $productionStop->stop_t = $row[$stopTIndex] ?? null;
                    $productionStop->wo_key = $row[$woKeyIndex] ?? null;
                    $productionStop->wo_name = $row[$woNameIndex] ?? null;
                    $productionStop->code1_key = $row[$code1KeyIndex] ?? null;
                    $productionStop->code2_key = $row[$code2KeyIndex] ?? null;
                    $productionStop->code3_key = $row[$code3KeyIndex] ?? null;
                    $productionStop->machine_name = $machineName;
                    
                    // Extract stop duration - may be directly available or need to be calculated
                    if (isset($row[$stopDurationIndex]) && is_numeric($row[$stopDurationIndex])) {
                        $productionStop->stop_duration = (float)$row[$stopDurationIndex];
                    } else {
                        // Try to find stop duration elsewhere or calculate it
                        foreach ($row as $cell) {
                            if (is_numeric($cell) && $cell > 0 && $cell < 100) { // Duration is likely to be in hours (0-100)
                                $productionStop->stop_duration = (float)$cell;
                                break;
                            }
                        }
                    }
                    
                    // If machine group wasn't found in this row but we know it from previous mapping
                    if (!$machineGroup && isset($machineGroups[$machineName])) {
                        $machineGroup = $machineGroups[$machineName];
                    }
                    
                    $productionStop->machine_group = $machineGroup;
                    
                    // Save if we have at least the basic information
                    if ($productionStop->from_date && $productionStop->machine_name) {
                        $productionStop->save();
                        $processed++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    Log::error("Error processing row {$index}: " . $e->getMessage());
                    $skipped++;
                }
            }
            
            // Commit the transaction
            DB::commit();
            
            return response()->json([
                'message' => 'File imported successfully',
                'processed' => $processed,
                'skipped' => $skipped
            ], 200);
            
        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();
            Log::error("Import error: " . $e->getMessage());
            
            return response()->json([
                'message' => 'Error importing file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Find the index of a column in the header row based on possible column names
     *
     * @param array $headerRow
     * @param array $possibleNames
     * @return int|null
     */
    private function findColumnIndex($headerRow, $possibleNames)
    {
        foreach ($possibleNames as $name) {
            foreach ($headerRow as $index => $header) {
                if ($header && is_string($header) && strtolower($header) === strtolower($name)) {
                    return $index;
                }
            }
        }
        
        // Default indices based on the image you provided
        $defaultIndices = [
            'from date' => 0,
            'to date' => 1,
            'mo key' => 2,
            'ws key' => 3,
            'stop t' => 4,
            'wo key' => 5,
            'wo name' => 6,
            'code1 key' => 7,
            'code2 key' => 8,
            'code3 key' => 9,
            'stop duration' => 10
        ];
        
        foreach ($possibleNames as $name) {
            if (array_key_exists($name, $defaultIndices)) {
                return $defaultIndices[$name];
            }
        }
        
        return null;
    }

    /**
     * Parse a date value from Excel
     *
     * @param mixed $value
     * @return string|null
     */
    private function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }
        
        try {
            // If it's a numeric Excel date
            if (is_numeric($value)) {
                return Date::excelToDateTimeObject($value)->format('Y-m-d');
            }
            
            // If it's a string date
            if (is_string($value)) {
                return Carbon::parse($value)->format('Y-m-d');
            }
        } catch (\Exception $e) {
            Log::warning("Failed to parse date: {$value}. Error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Get import history
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getImportHistory()
    {
        $stats = DB::table('production_stops')
            ->select(
                DB::raw('DATE(created_at) as import_date'),
                DB::raw('COUNT(*) as record_count'),
                DB::raw('MIN(from_date) as start_date'),
                DB::raw('MAX(to_date) as end_date')
            )
            ->groupBy('import_date')
            ->orderBy('import_date', 'desc')
            ->get();
            
        return response()->json([
            'history' => $stats
        ]);
    }
    
    /**
     * Delete imported data for a specific date range
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteImportedData(Request $request)
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date'
        ]);
        
        try {
            $fromDate = $request->from_date;
            $toDate = $request->to_date;
            
            $count = ProductionStop::whereBetween('from_date', [$fromDate, $toDate])->delete();
            
            return response()->json([
                'message' => "{$count} records deleted successfully",
                'deleted_count' => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error deleting records: ' . $e->getMessage()
            ], 500);
        }
    }
}