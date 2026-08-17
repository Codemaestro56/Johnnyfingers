<?php
/* Minimal FPDF class (subset) — simplified for certificate/receipt generation.
   This is a compact version implementing essential PDF text output.
   Note: For production consider using the official FPDF library.
*/
class FPDF {
    protected $pages = array();
    protected $current_page = '';
    protected $font = 'Helvetica';
    protected $font_size = 12;
    public function __construct($orientation='P',$unit='mm',$size='A4'){
    }
    public function AddPage(){
        $this->current_page = '';
        $this->pages[] = &$this->current_page;
    }
    public function SetFont($family, $style='', $size=12){
        $this->font = $family;
        $this->font_size = $size;
    }
    public function Cell($w, $h=0, $txt='', $border=0, $ln=0, $align='', $fill=false, $link=''){
        $this->current_page .= $txt . "\n";
    }
    public function Ln($h=null){
        $this->current_page .= "\n";
    }
    public function MultiCell($w, $h, $txt){
        $this->current_page .= $txt . "\n";
    }
    protected function _encode_text($txt){
        return $txt;
    }
    public function Output($dest='I', $name='doc.pdf'){
        // Very small PDF generator: create a simple PDF with only text in its content stream.
        $content = implode("\n\n", $this->pages);
        $lines = explode("\n", $content);
        $font = '/F1';
        $pdf = "%PDF-1.4\n";
        $objects = array();
        $n = 1;
        // Catalog
        $objects[$n] = "<< /Type /Catalog /Pages 2 0 R >>"; $n++;
        // Pages
        $objects[$n] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>"; $n++;
        // Page
        $mediaBox = '[0 0 595 842]';
        $objects[$n] = "<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox $mediaBox /Contents 5 0 R >>"; $n++;
        // Font
        $objects[$n] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"; $n++;
        // Content stream
        $text = "BT /F1 12 Tf 50 800 Td ";
        foreach ($lines as $i => $line) {
            $safe = preg_replace('/\\(/','\\\\(',$line);
            $safe = preg_replace('/\\)/','\\\\)',$safe);
            $text .= '(' . $safe . ') Tj T* ';
        }
        $stream = $text;
        $objects[$n] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream"; $n++;

        $xref = strlen($pdf);
        $offsets = array();
        foreach ($objects as $k => $obj) {
            $offsets[$k] = $xref;
            $s = (string)$obj;
            $pdf .= $k . " 0 obj\n" . $s . "\nendobj\n";
            $xref += strlen($k . " 0 obj\n" . $s . "\nendobj\n");
        }
        $startxref = $xref;
        $pdf .= "xref\n0 " . (count($objects)+1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf('%010d 00000 n \n', $off);
        }
        $pdf .= "trailer<< /Size " . (count($objects)+1) . " /Root 1 0 R >>\nstartxref\n".$startxref."\n%%EOF";

        if ($dest === 'I'){
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="'.basename($name).'"');
            echo $pdf;
            exit;
        } else {
            file_put_contents($name, $pdf);
        }
    }
}
