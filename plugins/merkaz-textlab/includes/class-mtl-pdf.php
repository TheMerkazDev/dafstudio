<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MTL_PDF {
    public static function detect_engine() {
        if ( class_exists( '\\Mpdf\\Mpdf' ) ) { return 'mpdf'; }
        if ( class_exists( '\\Dompdf\\Dompdf' ) ) { return 'dompdf'; }
        if ( class_exists( 'TCPDF' ) ) { return 'tcpdf'; }
        return 'print';
    }

    public static function status() {
        $engine = self::detect_engine();
        $labels = array( 'mpdf'=>'mPDF','dompdf'=>'Dompdf','tcpdf'=>'TCPDF','print'=>'Browser Print / Save as PDF' );
        return array( 'engine' => $engine, 'label' => $labels[ $engine ] ?? $engine, 'direct_download' => 'print' !== $engine );
    }

    public static function send_pdf( $html, $filename ) {
        $engine = self::detect_engine();
        $started = MTL_Debug::start_timer();
        MTL_Debug::debug( 'pdf', 'engine_selected', 'PDF generation engine selected.', array( 'engine' => $engine, 'filename' => $filename, 'html_bytes' => strlen( (string) $html ) ) );
        if ( 'mpdf' === $engine ) {
            $mpdf = new \Mpdf\Mpdf( array( 'format' => 'Letter', 'margin_left'=>25.4, 'margin_right'=>25.4, 'margin_top'=>25.4, 'margin_bottom'=>25.4, 'autoScriptToLang'=>true, 'autoLangToFont'=>true ) );
            if ( method_exists( $mpdf, 'SetDirectionality' ) ) { $mpdf->SetDirectionality( 'ltr' ); }
            $mpdf->WriteHTML( $html );
            $mpdf->Output( $filename, \Mpdf\Output\Destination::DOWNLOAD );
            exit;
        }
        if ( 'dompdf' === $engine ) {
            $options_class = '\\Dompdf\\Options';
            $options = class_exists( $options_class ) ? new $options_class() : null;
            if ( $options ) { $options->set( 'isRemoteEnabled', false ); }
            $dompdf = new \Dompdf\Dompdf( $options );
            $dompdf->setPaper( 'letter', 'portrait' );
            $dompdf->loadHtml( $html, 'UTF-8' );
            $dompdf->render();
            $dompdf->stream( $filename, array( 'Attachment' => true ) );
            exit;
        }
        if ( 'tcpdf' === $engine ) {
            $pdf = new TCPDF( 'P', 'mm', 'LETTER', true, 'UTF-8', false );
            $pdf->SetCreator( 'Merkaz TextLab' );
            $pdf->SetMargins( 25.4, 25.4, 25.4 );
            $pdf->SetAutoPageBreak( true, 25.4 );
            $pdf->AddPage();
            if ( method_exists( $pdf, 'setRTL' ) ) { $pdf->setRTL( false ); }
            $pdf->SetFont( 'dejavusans', '', 11 );
            $pdf->writeHTML( $html, true, false, true, false, '' );
            $pdf->Output( $filename, 'D' );
            exit;
        }
        MTL_Debug::warning( 'pdf', 'no_engine', 'No server-side PDF engine is available.', array( 'duration_ms' => MTL_Debug::duration_ms( $started ) ) );
        return false;
    }
}
