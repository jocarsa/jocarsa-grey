<?php
/**
 * Auto-generated method for column 'iva' (ODS formula: of:=[.C7]*0.21)
 * This file attempts to replicate the formula in PHP.
 * 
 * $row is provided by run_method.php.
 */

class FormulaRunner {
    public function cellVal($val) {
        $num = preg_replace('/[^0-9.\-]+/', '', $val);
        return floatval($num);
    }

    public function sum(...$vals) {
        $total = 0;
        foreach ($vals as $v) {
            $total += $v;
        }
        return $total;
    }

    public function roundVal($val, $precision=0) {
        return round($val, $precision);
    }

    public function run($row) {
        $result = $this->cellVal($row['precio'])*0.21;
        echo "<p>Formula: of:=[.C7]*0.21</p>";
        echo "<p>Resultado: {$result}</p>";
    }
}

$runner = new FormulaRunner();
$runner->run($row);
