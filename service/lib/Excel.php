<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

class Excel
{
    const TYPE_XLS = 'Xls';

    const TYPE_XLSX = 'Xlsx';

    private static $instance;

    private static $spreadSheet;

    private static $sheetIndex = 1;

    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    protected function __construct()
    {
    }

    protected function __clone()
    {
    }

    protected function __wakeup()
    {
    }

    /**
     * 获取电子表
     * @return Spreadsheet
     */
    public function getSpreadsheet()
    {
        if (is_null(self::$spreadSheet)) {
            self::$spreadSheet = new Spreadsheet();
        }

        return self::$spreadSheet;
    }

    /**
     * 获得工作表
     * @param $pIndex
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function getWorksheet($pIndex)
    {
        return $this->getSpreadsheet()->getSheet($pIndex);
    }

    /**
     * 添加工作表
     * @return $this
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    public function addWorksheet()
    {
        $spreadsheet = $this->getSpreadsheet();
        $spreadsheet->createSheet(self::$sheetIndex);
        $spreadsheet->setActiveSheetIndex(self::$sheetIndex);
        self::$sheetIndex++;

        return $this;
    }

    /**
     * 添加excel内容
     * @param array $head Excel 头部 ["COL1","COL2","COL3",...]
     * @param array $body 和头部长度相等字段查询出的数据就可以直接导出
     * @param string $sheetName
     * @param int $start
     * @return $this
     */
    public function addContent(array $head, array $body, $sheetName = 'Worksheet', $start = 2)
    {
        $spreadsheet = $this->getSpreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($sheetName);
        if (($count = count($head)) > 26) {
            $charIndex = [];
            $outsideLoopCount = ceil($count / 26);
            for ($j = 1; $j <= $outsideLoopCount; $j++) {
                $prefix = $j == 1 ? '' : chr(65 + $j - 2);
                // 最后一轮
                if ($j == $outsideLoopCount) {
                    $leftCount = $count % 26;
                    for ($i = 0; $i <= $leftCount; $i++) {
                        $charIndex[] = $prefix . chr(65 + $i);
                    }
                } else {
                    for ($i = 0; $i < 26; $i++) {
                        $charIndex[] = $prefix . chr(65 + $i);
                    }
                }
            }
        } else {
            $charIndex = range("A", "Z");
        }

        // Excel 表格头
        foreach ($head as $key => $val) {
            $worksheet->setCellValue("{$charIndex[$key]}1", $val);
        }

        // Excel body 部分
        foreach ($body as $key => $val) {
            $row = $key + $start;
            $col = 0;
            foreach ($val as $k => $v) {
                $worksheet->setCellValue("{$charIndex[$col]}{$row}", $v);
                $col++;
            }
        }

        return $this;
    }

    /**
     * 浏览器下载
     * @param string $filename 文件名
     * @param bool $download 是否浏览器下载
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     * @throws \Exception
     */
    public function save($filename = '', $download = false)
    {
        if (empty($filename)) {
            $filename = date('YmdHis');
        }
        if (empty(pathinfo($filename, PATHINFO_EXTENSION))) {
            $filename = $filename . '.xlsx';
        }
        $type = $this->getType($filename);
        $writer = IOFactory::createWriter($this->getSpreadsheet(), $type);
        if ($download) {
            $mime = [
                self::TYPE_XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                self::TYPE_XLS => 'application/vnd.ms-excel'
            ];
            header('Content-Type: ' . $mime[$type]);
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            $writer->save('php://output');
        } else {
            $writer->save($filename);
        }
    }

    /**
     * 解析 Excel 数据并写入到数据库
     * @param string $file Excel 路径名文件名
     * @param array $fields 表头对应字段信息 ['A'=>'field1', 'B'=>'field2', ...]
     * @param int $sheet 哪个sheet
     * @param int $start 数据开始读取行数
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     * @throws \Exception
     */
    public function parse($file, array $fields, $sheet = 0, $start = 2)
    {
        if (!is_file($file)) {
            throw new \Exception('file:' . $file . ' not exists!');
        }
        $reader = IOFactory::createReader($this->getType($file));
        $spreadsheet = $reader->load($file);
        // 数据数组
        $data = [];
        $worksheet = $spreadsheet->getSheet($sheet);
        $highestRow = $worksheet->getHighestRow();
        if ($start > $highestRow) {
            return $data;
        }
        // 指定跳过的行数
        foreach ($worksheet->getRowIterator($start) as $row) {
            // 逐个单元格读取，减少内存消耗
            $cellIterator = $row->getCellIterator();
            // 不跳过空值
            $cellIterator->setIterateOnlyExistingCells(false);
            // 只读取显示的行、列，跳过隐藏行、列
            if ($worksheet->getRowDimension($row->getRowIndex())->getVisible()) {
                $rowData = [];
                foreach ($cellIterator as $cell) {
                    if ($worksheet->getColumnDimension($cell->getColumn())->getVisible()) {
                        if (isset($fields[$cell->getColumn()])) {
                            $rowData[$fields[$cell->getColumn()]] = trim($cell->getValue());
                        }
                    }
                }
                $data[] = $rowData;
            }
        }

        return $data;
    }

    /**
     * 自动获取 Excel 类型
     * @param string $filename Excel 路径名文件名
     * @return string
     * @throws \Exception
     */
    protected function getType($filename)
    {
        $type = ucfirst(pathinfo($filename, PATHINFO_EXTENSION));
        if ($type !== self::TYPE_XLS && $type !== self::TYPE_XLSX) {
            throw new \Exception('illegal excel file extension');
        }

        return $type;
    }
}
