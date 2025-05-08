<?php

namespace App\Http\Controllers;

use App\Models\ProductionStop;
use App\Models\UserPreference;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStatistics(Request $request)
    {
        try {
            $query = ProductionStop::query();

            // Apply filters
            $this->applyDateFilters($query, $request);
            $this->applyMachineFilters($query, $request);
            $this->applyCodeFilters($query, $request);

            // Get total stop time
            $totalStopTime = $query->sum('stop_duration');

            // Statistics by machine
            $byMachine = $query->clone()
                ->select('machine_name', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                ->groupBy('machine_name')
                ->orderBy('total_duration', 'desc')
                ->get();

            // Statistics by code1 (type of intervention)
            $byCode1 = $query->clone()
                ->select('code1_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                ->groupBy('code1_key')
                ->orderBy('total_duration', 'desc')
                ->get();

            // Statistics by code2 (cause)
            $byCode2 = $query->clone()
                ->select('code2_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                ->groupBy('code2_key')
                ->orderBy('total_duration', 'desc')
                ->get();
                
            // Statistics by code3 (component)
            $byCode3 = $query->clone()
                ->select('code3_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                ->groupBy('code3_key')
                ->orderBy('total_duration', 'desc')
                ->get();

            // Trend data - stop times by day
            $trend = $query->clone()
                ->select(DB::raw('DATE(from_date) as date'), DB::raw('SUM(stop_duration) as total_duration'))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
                
            // Machine group statistics
            $byMachineGroup = $query->clone()
                ->select('machine_group', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                ->whereNotNull('machine_group')
                ->groupBy('machine_group')
                ->orderBy('total_duration', 'desc')
                ->get();
                
            // Top issues - combined statistics for identifying patterns
            $topIssues = $query->clone()
                ->select(
                    'machine_name',
                    'code1_key',
                    'code2_key',
                    'code3_key',
                    DB::raw('SUM(stop_duration) as total_duration'),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('AVG(stop_duration) as avg_duration')
                )
                ->groupBy('machine_name', 'code1_key', 'code2_key', 'code3_key')
                ->orderBy('total_duration', 'desc')
                ->limit(10)
                ->get();
                
            // Monthly comparison for trend analysis
            $monthlyComparison = $query->clone()
                ->select(
                    DB::raw('YEAR(from_date) as year'),
                    DB::raw('MONTH(from_date) as month'),
                    DB::raw('SUM(stop_duration) as total_duration'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('year', 'month')
                ->orderBy('year')
                ->orderBy('month')
                ->get();

            return response()->json([
                'totalStopTime' => $totalStopTime,
                'byMachine' => $byMachine,
                'byCode1' => $byCode1,
                'byCode2' => $byCode2,
                'byCode3' => $byCode3,
                'byMachineGroup' => $byMachineGroup,
                'trend' => $trend,
                'topIssues' => $topIssues,
                'monthlyComparison' => $monthlyComparison
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching dashboard statistics: " . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching dashboard statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available filter options for the dashboard
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFiltersData()
    {
        try {
            // Get list of years
            $years = ProductionStop::selectRaw('DISTINCT YEAR(from_date) as year')
                ->orderBy('year')
                ->pluck('year');
                
            // Get list of machines
            $machines = ProductionStop::select('machine_name')
                ->distinct()
                ->whereNotNull('machine_name')
                ->orderBy('machine_name')
                ->pluck('machine_name');
                
            // Get list of machine groups
            $machineGroups = ProductionStop::select('machine_group')
                ->distinct()
                ->whereNotNull('machine_group')
                ->orderBy('machine_group')
                ->pluck('machine_group');

            // Get list of code1 values (intervention types)
            $code1Values = ProductionStop::select('code1_key')
                ->distinct()
                ->whereNotNull('code1_key')
                ->orderBy('code1_key')
                ->pluck('code1_key');
                
            // Get list of code2 values (causes)
            $code2Values = ProductionStop::select('code2_key')
                ->distinct()
                ->whereNotNull('code2_key')
                ->orderBy('code2_key')
                ->pluck('code2_key');
                
            // Get list of code3 values (components)
            $code3Values = ProductionStop::select('code3_key')
                ->distinct()
                ->whereNotNull('code3_key')
                ->orderBy('code3_key')
                ->pluck('code3_key');
                
            // Date range information
            $dateRange = [
                'min' => ProductionStop::min('from_date'),
                'max' => ProductionStop::max('to_date')
            ];

            return response()->json([
                'years' => $years,
                'machines' => $machines,
                'machineGroups' => $machineGroups,
                'code1Values' => $code1Values,
                'code2Values' => $code2Values,
                'code3Values' => $code3Values,
                'dateRange' => $dateRange
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching filter options: " . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching filter options: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed production stop data with pagination
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getDetailedData(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);
            
            $query = ProductionStop::query();

            // Apply filters
            $this->applyDateFilters($query, $request);
            $this->applyMachineFilters($query, $request);
            $this->applyCodeFilters($query, $request);

            // Sort by the most recent stops first
            $sortField = $request->input('sort_field', 'from_date');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            // Paginate the results
            $data = $query->paginate($perPage, ['*'], 'page', $page);
            
            return response()->json($data);
        } catch (\Exception $e) {
            Log::error("Error fetching detailed data: " . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching detailed data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export dashboard data to Excel
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function exportData(Request $request)
    {
        try {
            $query = ProductionStop::query();

            // Apply filters
            $this->applyDateFilters($query, $request);
            $this->applyMachineFilters($query, $request);
            $this->applyCodeFilters($query, $request);

            // Get the data
            $data = $query->orderBy('from_date', 'desc')->get();
            
            // Create a new spreadsheet
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Add headers
            $headers = [
                'Date From', 'Date To', 'Machine', 'Machine Group', 
                'MO Key', 'WS Key', 'Stop Type', 
                'WO Key', 'WO Name', 'Type', 'Cause', 'Component', 
                'Duration (Hours)'
            ];
            
            foreach ($headers as $index => $header) {
                $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
            }
            
            // Add data
            $row = 2;
            foreach ($data as $item) {
                $sheet->setCellValueByColumnAndRow(1, $row, $item->from_date);
                $sheet->setCellValueByColumnAndRow(2, $row, $item->to_date);
                $sheet->setCellValueByColumnAndRow(3, $row, $item->machine_name);
                $sheet->setCellValueByColumnAndRow(4, $row, $item->machine_group);
                $sheet->setCellValueByColumnAndRow(5, $row, $item->mo_key);
                $sheet->setCellValueByColumnAndRow(6, $row, $item->ws_key);
                $sheet->setCellValueByColumnAndRow(7, $row, $item->stop_t);
                $sheet->setCellValueByColumnAndRow(8, $row, $item->wo_key);
                $sheet->setCellValueByColumnAndRow(9, $row, $item->wo_name);
                $sheet->setCellValueByColumnAndRow(10, $row, $item->code1_key);
                $sheet->setCellValueByColumnAndRow(11, $row, $item->code2_key);
                $sheet->setCellValueByColumnAndRow(12, $row, $item->code3_key);
                $sheet->setCellValueByColumnAndRow(13, $row, $item->stop_duration);
                $row++;
            }
            
            // Auto-size columns
            foreach (range('A', 'M') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            
            // Create Excel file
            $writer = new Xlsx($spreadsheet);
            $filename = 'production_stops_' . date('Ymd_His') . '.xlsx';
            $tempPath = storage_path('app/public/' . $filename);
            $writer->save($tempPath);
            
            return Response::download($tempPath, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error("Error exporting data: " . $e->getMessage());
            return response()->json([
                'message' => 'Error exporting data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save dashboard settings
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveSettings(Request $request)
    {
        try {
            $settings = $request->all();
            
            // Save to a config file or database settings table
            // For this example, we'll just return the settings as if they were saved
            
            return response()->json([
                'message' => 'Settings saved successfully',
                'settings' => $settings
            ]);
        } catch (\Exception $e) {
            Log::error("Error saving settings: " . $e->getMessage());
            return response()->json([
                'message' => 'Error saving settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard settings
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings()
    {
        try {
            // Retrieve from config or database
            // For this example, we'll return default settings
            
            $settings = [
                'defaultChartType' => 'bar',
                'refreshInterval' => 0, // 0 = no auto refresh
                'defaultDateRange' => 'month',
                'colorScheme' => 'default',
                'dashboardLayout' => [
                    'byMachine' => true,
                    'byCode1' => true,
                    'byCode2' => true,
                    'byCode3' => true,
                    'trend' => true,
                    'topIssues' => true
                ]
            ];
            
            return response()->json($settings);
        } catch (\Exception $e) {
            Log::error("Error fetching settings: " . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save user preferences
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveUserPreferences(Request $request)
    {
        try {
            $userId = $request->input('user_id', 1); // Default to user ID 1 for this example
            $preferences = $request->input('preferences', []);
            
            // In a real application, you would save this to a user_preferences table
            // For this example, we'll just return the preferences as if they were saved
            
            return response()->json([
                'message' => 'User preferences saved successfully',
                'user_id' => $userId,
                'preferences' => $preferences
            ]);
        } catch (\Exception $e) {
            Log::error("Error saving user preferences: " . $e->getMessage());
            return response()->json([
                'message' => 'Error saving user preferences: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user preferences
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserPreferences(Request $request)
    {
        try {
            $userId = $request->input('user_id', 1); // Default to user ID 1 for this example
            
            // In a real application, you would retrieve this from a user_preferences table
            // For this example, we'll return default preferences
            
            $preferences = [
                'defaultFilters' => [
                    'year' => date('Y'),
                    'month' => date('n'),
                    'machine' => null
                ],
                'chartColors' => 'default',
                'dashboardLayout' => 'standard'
            ];
            
            return response()->json([
                'user_id' => $userId,
                'preferences' => $preferences
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching user preferences: " . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching user preferences: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics for a specific machine
     *
     * @param Request $request
     * @param string $machine
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMachineStatistics(Request $request, $machine)
    {
        try {
            $query = ProductionStop::where('machine_name', $machine);
            
            // Apply date filters
            $this->applyDateFilters($query, $request);
            
            // Get total stop time for this machine
            $totalStopTime = $query->sum('stop_duration');
            
            // Get count of stops
            $stopsCount = $query->count();
            
            // Get average duration
            $avgDuration = $stopsCount > 0 ? $totalStopTime / $stopsCount : 0;
            
            // Most common causes (code2)
            $commonCauses = $query->clone()
                ->select('code2_key', DB::raw('COUNT(*) as count'), DB::raw('SUM(stop_duration) as total_duration'))
                ->groupBy('code2_key')
                ->orderBy('count', 'desc')
                ->limit(5)
                ->get();
                
            // Most time-consuming components (code3)
            $timeConsumingComponents = $query->clone()
                ->select('code3_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                ->groupBy('code3_key')
                ->orderBy('total_duration', 'desc')
                ->limit(5)
                ->get();
                
            // Time trend for this machine
            $trend = $query->clone()
                ->select(DB::raw('DATE(from_date) as date'), DB::raw('SUM(stop_duration) as total_duration'))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
                
            return response()->json([
                'machine' => $machine,
                'totalStopTime' => $totalStopTime,
                'stopsCount' => $stopsCount,
                'avgDuration' => $avgDuration,
                'commonCauses' => $commonCauses,
                'timeConsumingComponents' => $timeConsumingComponents,
                'trend' => $trend
            ]);
        } catch (\Exception $e) {
            Log::error("Error fetching machine statistics: " . $e->getMessage());
            return response()->json([
                'message' => 'Error fetching machine statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get comparison data for different time periods
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getComparisonData(Request $request)
    {
        try {
            $period1Start = $request->input('period1_start');
            $period1End = $request->input('period1_end');
            $period2Start = $request->input('period2_start');
            $period2End = $request->input('period2_end');
            
            // Validate dates
            if (!$period1Start || !$period1End || !$period2Start || !$period2End) {
                return response()->json([ 'message' => 'All date parameters (period1_start, period1_end, period2_start, period2_end) are required'
            ], 400);
        }
          // Get data for period 1
          $period1Query = ProductionStop::whereBetween('from_date', [$period1Start, $period1End]);
          $this->applyMachineFilters($period1Query, $request);
          
          $period1Data = [
              'totalStopTime' => $period1Query->clone()->sum('stop_duration'),
              'stopsCount' => $period1Query->clone()->count(),
              'byMachine' => $period1Query->clone()
                  ->select('machine_name', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                  ->groupBy('machine_name')
                  ->orderBy('total_duration', 'desc')
                  ->get(),
              'byCode1' => $period1Query->clone()
                  ->select('code1_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                  ->groupBy('code1_key')
                  ->orderBy('total_duration', 'desc')
                  ->get(),
              'byCode2' => $period1Query->clone()
                  ->select('code2_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                  ->groupBy('code2_key')
                  ->orderBy('total_duration', 'desc')
                  ->get()
          ];
          
          // Get data for period 2
          $period2Query = ProductionStop::whereBetween('from_date', [$period2Start, $period2End]);
          $this->applyMachineFilters($period2Query, $request);
          
          $period2Data = [
              'totalStopTime' => $period2Query->clone()->sum('stop_duration'),
              'stopsCount' => $period2Query->clone()->count(),
              'byMachine' => $period2Query->clone()
                  ->select('machine_name', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                  ->groupBy('machine_name')
                  ->orderBy('total_duration', 'desc')
                  ->get(),
              'byCode1' => $period2Query->clone()
                  ->select('code1_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                  ->groupBy('code1_key')
                  ->orderBy('total_duration', 'desc')
                  ->get(),
              'byCode2' => $period2Query->clone()
                  ->select('code2_key', DB::raw('SUM(stop_duration) as total_duration'), DB::raw('COUNT(*) as count'))
                  ->groupBy('code2_key')
                  ->orderBy('total_duration', 'desc')
                  ->get()
          ];
          
          // Calculate differences and percentages
          $differences = [
              'totalStopTime' => [
                  'absolute' => $period2Data['totalStopTime'] - $period1Data['totalStopTime'],
                  'percentage' => $period1Data['totalStopTime'] > 0 
                      ? ($period2Data['totalStopTime'] - $period1Data['totalStopTime']) / $period1Data['totalStopTime'] * 100 
                      : null
              ],
              'stopsCount' => [
                  'absolute' => $period2Data['stopsCount'] - $period1Data['stopsCount'],
                  'percentage' => $period1Data['stopsCount'] > 0 
                      ? ($period2Data['stopsCount'] - $period1Data['stopsCount']) / $period1Data['stopsCount'] * 100 
                      : null
              ]
          ];
          
          return response()->json([
              'period1' => [
                  'start' => $period1Start,
                  'end' => $period1End,
                  'data' => $period1Data
              ],
              'period2' => [
                  'start' => $period2Start,
                  'end' => $period2End,
                  'data' => $period2Data
              ],
              'differences' => $differences
          ]);
      } catch (\Exception $e) {
          Log::error("Error fetching comparison data: " . $e->getMessage());
          return response()->json([
              'message' => 'Error fetching comparison data: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Get top issues by duration and frequency
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function getTopIssues(Request $request)
  {
      try {
          $limit = $request->input('limit', 10);
          $query = ProductionStop::query();
          
          // Apply filters
          $this->applyDateFilters($query, $request);
          $this->applyMachineFilters($query, $request);
          
          // Get top issues by duration
          $topByDuration = $query->clone()
              ->select(
                  'machine_name',
                  'code1_key',
                  'code2_key',
                  'code3_key',
                  DB::raw('SUM(stop_duration) as total_duration'),
                  DB::raw('COUNT(*) as count'),
                  DB::raw('AVG(stop_duration) as avg_duration')
              )
              ->groupBy('machine_name', 'code1_key', 'code2_key', 'code3_key')
              ->orderBy('total_duration', 'desc')
              ->limit($limit)
              ->get();
              
          // Get top issues by frequency
          $topByFrequency = $query->clone()
              ->select(
                  'machine_name',
                  'code1_key',
                  'code2_key',
                  'code3_key',
                  DB::raw('SUM(stop_duration) as total_duration'),
                  DB::raw('COUNT(*) as count'),
                  DB::raw('AVG(stop_duration) as avg_duration')
              )
              ->groupBy('machine_name', 'code1_key', 'code2_key', 'code3_key')
              ->orderBy('count', 'desc')
              ->limit($limit)
              ->get();
              
          return response()->json([
              'topByDuration' => $topByDuration,
              'topByFrequency' => $topByFrequency
          ]);
      } catch (\Exception $e) {
          Log::error("Error fetching top issues: " . $e->getMessage());
          return response()->json([
              'message' => 'Error fetching top issues: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Get recurring issues (patterns over time)
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function getRecurringIssues(Request $request)
  {
      try {
          $minOccurrences = $request->input('min_occurrences', 3);
          $query = ProductionStop::query();
          
          // Apply filters
          $this->applyDateFilters($query, $request);
          $this->applyMachineFilters($query, $request);
          
          // Get recurring issues
          $recurringIssues = $query->clone()
              ->select(
                  'machine_name',
                  'code1_key',
                  'code2_key',
                  'code3_key',
                  DB::raw('COUNT(*) as occurrences'),
                  DB::raw('SUM(stop_duration) as total_duration'),
                  DB::raw('AVG(stop_duration) as avg_duration'),
                  DB::raw('MIN(from_date) as first_occurrence'),
                  DB::raw('MAX(from_date) as last_occurrence')
              )
              ->groupBy('machine_name', 'code1_key', 'code2_key', 'code3_key')
              ->having('occurrences', '>=', $minOccurrences)
              ->orderBy('occurrences', 'desc')
              ->get();
              
          return response()->json($recurringIssues);
      } catch (\Exception $e) {
          Log::error("Error fetching recurring issues: " . $e->getMessage());
          return response()->json([
              'message' => 'Error fetching recurring issues: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Get efficiency metrics
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function getEfficiencyMetrics(Request $request)
  {
      try {
          $query = ProductionStop::query();
          
          // Apply filters
          $this->applyDateFilters($query, $request);
          $this->applyMachineFilters($query, $request);
          
          // Calculate statistics
          $totalStopTime = $query->clone()->sum('stop_duration');
          
          // Assume total available time (for example, 24 hours per day for the selected period)
          $fromDate = $request->input('from_date', Carbon::now()->subMonth()->format('Y-m-d'));
          $toDate = $request->input('to_date', Carbon::now()->format('Y-m-d'));
          
          $daysInPeriod = Carbon::parse($fromDate)->diffInDays(Carbon::parse($toDate)) + 1;
          $totalAvailableTime = $daysInPeriod * 24; // 24 hours per day
          
          // Calculate metrics
          $downtime = $totalStopTime;
          $uptime = $totalAvailableTime - $downtime;
          $uptimePercentage = ($uptime / $totalAvailableTime) * 100;
          
          // Calculate MTBF (Mean Time Between Failures) and MTTR (Mean Time To Repair)
          $stopsCount = $query->clone()->count();
          $mtbf = $stopsCount > 0 ? $uptime / $stopsCount : null;
          $mttr = $stopsCount > 0 ? $downtime / $stopsCount : null;
          
          // Calculate efficiency by machine
          $efficiencyByMachine = $query->clone()
              ->select('machine_name', 
                  DB::raw('SUM(stop_duration) as downtime'),
                  DB::raw('COUNT(*) as stops_count')
              )
              ->groupBy('machine_name')
              ->get()
              ->map(function($item) use ($daysInPeriod) {
                  $totalAvailableTime = $daysInPeriod * 24; // 24 hours per day
                  $uptime = $totalAvailableTime - $item->downtime;
                  $uptimePercentage = ($uptime / $totalAvailableTime) * 100;
                  $mtbf = $item->stops_count > 0 ? $uptime / $item->stops_count : null;
                  $mttr = $item->stops_count > 0 ? $item->downtime / $item->stops_count : null;
                  
                  return [
                      'machine_name' => $item->machine_name,
                      'downtime' => $item->downtime,
                      'uptime' => $uptime,
                      'uptime_percentage' => $uptimePercentage,
                      'stops_count' => $item->stops_count,
                      'mtbf' => $mtbf,
                      'mttr' => $mttr
                  ];
              });
          
          return response()->json([
              'overall' => [
                  'totalAvailableTime' => $totalAvailableTime,
                  'downtime' => $downtime,
                  'uptime' => $uptime,
                  'uptimePercentage' => $uptimePercentage,
                  'stopsCount' => $stopsCount,
                  'mtbf' => $mtbf,
                  'mttr' => $mttr
              ],
              'byMachine' => $efficiencyByMachine
          ]);
      } catch (\Exception $e) {
          Log::error("Error fetching efficiency metrics: " . $e->getMessage());
          return response()->json([
              'message' => 'Error fetching efficiency metrics: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Get machine status (current or most recent)
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function getMachineStatus(Request $request)
  {
      try {
          // Get list of machines
          $machines = ProductionStop::select('machine_name')
              ->distinct()
              ->whereNotNull('machine_name')
              ->pluck('machine_name');
              
          $statuses = [];
          
          foreach ($machines as $machine) {
              // Get most recent stop for this machine
              $latestStop = ProductionStop::where('machine_name', $machine)
                  ->orderBy('from_date', 'desc')
                  ->orderBy('to_date', 'desc')
                  ->first();
                  
              if ($latestStop) {
                  $statuses[] = [
                      'machine_name' => $machine,
                      'last_stop_date' => $latestStop->from_date,
                      'last_stop_duration' => $latestStop->stop_duration,
                      'last_stop_reason' => [
                          'type' => $latestStop->code1_key,
                          'cause' => $latestStop->code2_key,
                          'component' => $latestStop->code3_key
                      ],
                      'total_stops_last_30_days' => ProductionStop::where('machine_name', $machine)
                          ->where('from_date', '>=', Carbon::now()->subDays(30)->format('Y-m-d'))
                          ->count(),
                      'total_downtime_last_30_days' => ProductionStop::where('machine_name', $machine)
                          ->where('from_date', '>=', Carbon::now()->subDays(30)->format('Y-m-d'))
                          ->sum('stop_duration')
                  ];
              }
          }
          
          return response()->json($statuses);
      } catch (\Exception $e) {
          Log::error("Error fetching machine status: " . $e->getMessage());
          return response()->json([
              'message' => 'Error fetching machine status: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Get notifications based on production stop patterns
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function getNotifications(Request $request)
  {
      try {
          $notifications = [];
          
          // Check for frequent stops in the last 7 days
          $frequentStopsThreshold = 5;
          $frequentStops = DB::table('production_stops')
              ->select('machine_name', DB::raw('COUNT(*) as count'))
              ->where('from_date', '>=', Carbon::now()->subDays(7)->format('Y-m-d'))
              ->groupBy('machine_name')
              ->having('count', '>=', $frequentStopsThreshold)
              ->orderBy('count', 'desc')
              ->get();
              
          foreach ($frequentStops as $machine) {
              $notifications[] = [
                  'type' => 'warning',
                  'message' => "Machine {$machine->machine_name} has experienced {$machine->count} stops in the last 7 days",
                  'timestamp' => Carbon::now()->toIso8601String()
              ];
          }
          
          // Check for long duration stops in the last 30 days
          $longDurationThreshold = 4; // hours
          $longDurationStops = DB::table('production_stops')
              ->select('machine_name', 'from_date', 'stop_duration', 'code2_key', 'code3_key')
              ->where('from_date', '>=', Carbon::now()->subDays(30)->format('Y-m-d'))
              ->where('stop_duration', '>=', $longDurationThreshold)
              ->orderBy('stop_duration', 'desc')
              ->get();
              
          foreach ($longDurationStops as $stop) {
              $notifications[] = [
                  'type' => 'alert',
                  'message' => "Machine {$stop->machine_name} had a {$stop->stop_duration} hour stop on {$stop->from_date} due to {$stop->code2_key} ({$stop->code3_key})",
                  'timestamp' => Carbon::now()->toIso8601String()
              ];
          }
          
          // Check for recurring issues with the same component
          $recurringComponentIssues = DB::table('production_stops')
              ->select('machine_name', 'code3_key', DB::raw('COUNT(*) as count'))
              ->where('from_date', '>=', Carbon::now()->subDays(30)->format('Y-m-d'))
              ->groupBy('machine_name', 'code3_key')
              ->having('count', '>=', 3)
              ->orderBy('count', 'desc')
              ->get();
              
          foreach ($recurringComponentIssues as $issue) {
              $notifications[] = [
                  'type' => 'info',
                  'message' => "Component {$issue->code3_key} on machine {$issue->machine_name} has failed {$issue->count} times in the last 30 days",
                  'timestamp' => Carbon::now()->toIso8601String()
              ];
          }
          
          return response()->json($notifications);
      } catch (\Exception $e) {
          Log::error("Error generating notifications: " . $e->getMessage());
          return response()->json([
              'message' => 'Error generating notifications: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Mark notifications as read
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function markNotificationsAsRead(Request $request)
  {
      try {
          // In a real application, this would update notification status in a database
          // For this example, we'll just return a success message
          
          return response()->json([
              'message' => 'Notifications marked as read'
          ]);
      } catch (\Exception $e) {
          Log::error("Error marking notifications as read: " . $e->getMessage());
          return response()->json([
              'message' => 'Error marking notifications as read: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Update notification settings
   *
   * @param Request $request
   * @return \Illuminate\Http\JsonResponse
   */
  public function updateNotificationSettings(Request $request)
  {
      try {
          $settings = $request->all();
          
          // In a real application, this would update notification settings in a database
          // For this example, we'll just return the settings as if they were saved
          
          return response()->json([
              'message' => 'Notification settings updated successfully',
              'settings' => $settings
          ]);
      } catch (\Exception $e) {
          Log::error("Error updating notification settings: " . $e->getMessage());
          return response()->json([
              'message' => 'Error updating notification settings: ' . $e->getMessage()
          ], 500);
      }
  }

  /**
   * Apply date filters to a query
   *
   * @param \Illuminate\Database\Eloquent\Builder $query
   * @param Request $request
   * @return void
   */
  private function applyDateFilters($query, Request $request)
  {
      // Filter by year
      if ($request->has('year') && $request->year !== '') {
          $query->whereYear('from_date', $request->year);
      }
      
      // Filter by month
      if ($request->has('month') && $request->month !== '') {
          $query->whereMonth('from_date', $request->month);
      }
      
      // Filter by week
      if ($request->has('week') && $request->week !== '') {
          $query->whereRaw('WEEK(from_date) = ?', [$request->week]);
      }
      
      // Filter by day
      if ($request->has('day') && $request->day !== '') {
          $query->whereDay('from_date', $request->day);
      }
      
      // Filter by date range
      if ($request->has('from_date') && $request->from_date !== '') {
          $query->where('from_date', '>=', $request->from_date);
      }
      
      if ($request->has('to_date') && $request->to_date !== '') {
          $query->where('from_date', '<=', $request->to_date);
      }
  }

  /**
   * Apply machine filters to a query
   *
   * @param \Illuminate\Database\Eloquent\Builder $query
   * @param Request $request
   * @return void
   */
  private function applyMachineFilters($query, Request $request)
  {
      // Filter by machine
      if ($request->has('machine') && $request->machine !== '') {
          $query->where('machine_name', $request->machine);
      }
      
      // Filter by machine group
      if ($request->has('machine_group') && $request->machine_group !== '') {
          $query->where('machine_group', $request->machine_group);
      }
  }

  /**
   * Apply code filters to a query
   *
   * @param \Illuminate\Database\Eloquent\Builder $query
   * @param Request $request
   * @return void
   */
  private function applyCodeFilters($query, Request $request)
  {
      // Filter by code1 (intervention type)
      if ($request->has('code1') && $request->code1 !== '') {
          $query->where('code1_key', $request->code1);
      }
      
      // Filter by code2 (cause)
      if ($request->has('code2') && $request->code2 !== '') {
          $query->where('code2_key', $request->code2);
      }
      
      // Filter by code3 (component)
      if ($request->has('code3') && $request->code3 !== '') {
          $query->where('code3_key', $request->code3);
      }
  }
}