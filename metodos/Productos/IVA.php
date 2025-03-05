<?php
/**
 * Auto-generated method for column 'IVA' (ODS formula: of:=[.C7]*0.21)
 * This file replicates the formula in PHP.
 * $row is provided by run_method.php.
 */

class FormulaRunner {
    public function cellVal($val) {
        $num = preg_replace('/[^0-9.\-]+/', '', $val);
        return floatval($num);
    }

    public function sum(...$vals) {
        $total = 0;
        foreach ($vals as $v) { $total += $v; }
        return $total;
    }

    public function roundVal($val, $precision=0) {
        return round($val, $precision);
    }

    public function run($row) {
        $result = $this->cellVal($row['Precio'])*0.21;
        echo "<p>Formula: of:=[.C7]*0.21</p>";
        echo "<p>Resultado: {$result}</p>";
    }
}

$runner = new FormulaRunner();
$runner->run($row);
