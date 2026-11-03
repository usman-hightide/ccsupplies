<?php
/**
 * Minimal .xlsx writer (no library): styled sheets for Supply Manager report exports.
 *
 * A cell is a scalar or [ value, style ]; styles are the S_* constants below.
 * Numbers are written as numbers so Excel can sum and filter them.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WCSS_Xlsx {

    const S_TEXT      = 0;
    const S_TITLE     = 1;
    const S_MUTED     = 2;
    const S_HEAD      = 3;
    const S_CELL      = 4;
    const S_MONEY     = 5;
    const S_INT       = 6;
    const S_PCT       = 7;
    const S_TOT_LABEL = 8;
    const S_TOT_MONEY = 9;
    const S_TOT_INT   = 10;
    const S_LABEL     = 11;
    const S_BAND      = 12;
    const S_BAND_SUB  = 13;
    const S_TOT_PCT   = 14;
    const S_SECTION   = 15;

    private $sheets = [];

    /**
     * @param array $opts widths (array of column widths), freeze (row number to freeze above, 1-based),
     *                    filter ('A5:F20'), merges (['A1:F1', …]), landscape (bool).
     */
    public function add_sheet( string $name, array $rows, array $opts = [] ) {
        $name = substr( preg_replace( '/[\[\]\*\?\/\\\\:]/', ' ', $name ), 0, 31 );
        $this->sheets[] = [ 'name' => $name ?: 'Sheet' . ( count( $this->sheets ) + 1 ), 'rows' => $rows, 'opts' => $opts ];
    }

    public static function col( int $i ): string {
        $s = '';
        for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
            $s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
        }
        return $s;
    }

    /** Write the workbook to a temp file and return its path. */
    public function save(): string {
        $path = tempnam( get_temp_dir(), 'ccx' ); // wp_tempnam() only exists in wp-admin
        $zip  = new ZipArchive();
        if ( $zip->open( $path, ZipArchive::OVERWRITE | ZipArchive::CREATE ) !== true ) {
            throw new RuntimeException( 'Could not create the Excel file.' );
        }
        $n = count( $this->sheets );

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        for ( $i = 1; $i <= $n; $i++ ) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString( '[Content_Types].xml', $ct . '</Types>' );

        $zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>' );

        $zip->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Canna Cabana Supply Manager report</dc:title><dc:creator>Supply Manager</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created></cp:coreProperties>' );

        $wb   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ( $this->sheets as $i => $s ) {
            $k     = $i + 1;
            $wb   .= '<sheet name="' . $this->x( $s['name'] ) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString( 'xl/worksheets/sheet' . $k . '.xml', $this->sheet_xml( $s ) );
        }
        $rels .= '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString( 'xl/workbook.xml', $wb . '</sheets></workbook>' );
        $zip->addFromString( 'xl/_rels/workbook.xml.rels', $rels );
        $zip->addFromString( 'xl/styles.xml', $this->styles_xml() );
        $zip->close();
        return $path;
    }

    private function x( $s ): string {
        // Strip characters XML 1.0 does not allow, then escape.
        $s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $s );
        return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    private function sheet_xml( array $s ): string {
        $o   = $s['opts'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ( ! empty( $o['freeze'] ) ) {
            $xml .= '<pane ySplit="' . (int) $o['freeze'] . '" topLeftCell="A' . ( (int) $o['freeze'] + 1 ) . '" activePane="bottomLeft" state="frozen"/>';
        }
        $xml .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="16"/>';
        if ( ! empty( $o['widths'] ) ) {
            $xml .= '<cols>';
            foreach ( array_values( $o['widths'] ) as $i => $w ) {
                $xml .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ( array_values( $s['rows'] ) as $r => $row ) {
            $rn   = $r + 1;
            $ht   = $o['heights'][ $rn ] ?? null;
            $xml .= '<row r="' . $rn . '"' . ( $ht ? ' ht="' . (float) $ht . '" customHeight="1"' : '' ) . '>';
            foreach ( array_values( (array) $row ) as $c => $cell ) {
                [ $v, $st ] = is_array( $cell ) ? [ $cell[0], $cell[1] ?? self::S_TEXT ] : [ $cell, self::S_TEXT ];
                $ref = self::col( $c ) . $rn;
                if ( $v === null || $v === '' ) {
                    $xml .= '<c r="' . $ref . '" s="' . (int) $st . '"/>';
                } elseif ( is_int( $v ) || is_float( $v ) ) {
                    $xml .= '<c r="' . $ref . '" s="' . (int) $st . '"><v>' . ( is_float( $v ) ? round( $v, 6 ) : $v ) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" s="' . (int) $st . '" t="inlineStr"><is><t xml:space="preserve">' . $this->x( $v ) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ( ! empty( $o['filter'] ) ) {
            $xml .= '<autoFilter ref="' . $this->x( $o['filter'] ) . '"/>';
        }
        if ( ! empty( $o['merges'] ) ) {
            $xml .= '<mergeCells count="' . count( $o['merges'] ) . '">';
            foreach ( $o['merges'] as $m ) {
                $xml .= '<mergeCell ref="' . $this->x( $m ) . '"/>';
            }
            $xml .= '</mergeCells>';
        }
        $xml .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';
        $xml .= '<pageSetup orientation="' . ( ! empty( $o['landscape'] ) ? 'landscape' : 'portrait' ) . '" fitToWidth="1" fitToHeight="0"/>';
        $xml .= '<headerFooter><oddFooter>&amp;L&amp;8Canna Cabana · Supply Manager&amp;R&amp;8Page &amp;P of &amp;N</oddFooter></headerFooter>';
        return $xml . '</worksheet>';
    }

    private function styles_xml(): string {
        $money = '&quot;CA$&quot;#,##0.00';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="' . $money . '"/><numFmt numFmtId="165" formatCode="0.0%"/></numFmts>'
            . '<fonts count="7">'
            . '<font><sz val="11"/><color rgb="FF16181D"/><name val="Calibri"/></font>'                       // 0 body
            . '<font><b/><sz val="18"/><color rgb="FF16181D"/><name val="Calibri"/></font>'                 // 1 title
            . '<font><sz val="10"/><color rgb="FF5B6270"/><name val="Calibri"/></font>'                      // 2 muted
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                 // 3 white bold
            . '<font><b/><sz val="11"/><color rgb="FF16181D"/><name val="Calibri"/></font>'                 // 4 bold
            . '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'                 // 5 band
            . '<font><sz val="10"/><color rgb="FFC9CDD4"/><name val="Calibri"/></font>'                      // 6 band sub
            . '</fonts>'
            . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF16181D"/><bgColor indexed="64"/></patternFill></fill>'     // 2 ink
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF4F5F7"/><bgColor indexed="64"/></patternFill></fill>'     // 3 light
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF121417"/><bgColor indexed="64"/></patternFill></fill>'     // 4 band
            . '</fills>'
            . '<borders count="4"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FFE4E7EC"/></bottom><diagonal/></border>'          // 1 row line
            . '<border><left/><right/><top style="medium"><color rgb="FF16181D"/></top><bottom style="medium"><color rgb="FF16181D"/></bottom><diagonal/></border>' // 2 totals
            . '<border><left/><right/><top/><bottom style="thick"><color rgb="FFC8372D"/></bottom><diagonal/></border>'         // 3 brand accent
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="16">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                         // 0 text
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                          // 1 title
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment wrapText="1" vertical="top"/></xf>' // 2 muted
            . '<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center" wrapText="1"/></xf>' // 3 header
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"><alignment vertical="top" wrapText="1"/></xf>' // 4 cell
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>' // 5 money
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>'   // 6 int
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"><alignment vertical="top"/></xf>' // 7 pct
            . '<xf numFmtId="0" fontId="4" fillId="3" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'             // 8 total label
            . '<xf numFmtId="164" fontId="4" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' // 9 total money
            . '<xf numFmtId="3" fontId="4" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'   // 10 total int
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"><alignment vertical="top"/></xf>' // 11 label
            . '<xf numFmtId="0" fontId="5" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center"/></xf>' // 12 band
            . '<xf numFmtId="0" fontId="6" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" horizontal="right"/></xf>' // 13 band sub
            . '<xf numFmtId="165" fontId="4" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' // 14 total pct
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                          // 15 section
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
