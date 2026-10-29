import zipfile
import html
import os
import sys

def xml_escape(text):
    if text is None:
        return ""
    return html.escape(str(text))

class DocxBuilder:
    def __init__(self, filename="Tweak_Insight_Logistics_Investor_Pitch_&_Financial_Model.docx"):
        self.filename = filename
        self.body_elements = []
        self.page_width = 12240   # 8.5 inches
        self.page_height = 15840  # 11 inches
        self.margin = 1440        # 1 inch
        self.content_width = 9360 # 6.5 inches

    def add_page_break(self):
        self.body_elements.append('''
        <w:p>
          <w:r>
            <w:br w:type="page"/>
          </w:r>
        </w:p>
        ''')

    def add_title_block(self, title, subtitle, metadata_dict):
        # Decorative top border
        self.body_elements.append('''
        <w:p>
          <w:pPr>
            <w:spacing w:before="720" w:after="240"/>
            <w:jc w:val="center"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:b/>
              <w:sz w:val="56"/>
              <w:color w:val="991B1B"/>
            </w:rPr>
            <w:t>''' + xml_escape(title) + '''</w:t>
          </w:r>
        </w:p>
        ''')

        # Subtitle
        self.body_elements.append('''
        <w:p>
          <w:pPr>
            <w:spacing w:before="0" w:after="480"/>
            <w:jc w:val="center"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:b/>
              <w:sz w:val="26"/>
              <w:color w:val="0F172A"/>
            </w:rPr>
            <w:t>''' + xml_escape(subtitle) + '''</w:t>
          </w:r>
        </w:p>
        ''')

        # Metadata card
        meta_rows = []
        for k, v in metadata_dict.items():
            meta_rows.append(f'''
            <w:tr>
              <w:tc>
                <w:tcPr>
                  <w:tcW w:w="3000" w:type="dxa"/>
                  <w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>
                </w:tcPr>
                <w:p>
                  <w:pPr><w:spacing w:before="80" w:after="80"/></w:pPr>
                  <w:r>
                    <w:rPr><w:b/><w:sz w:val="20"/><w:color w:val="475569"/></w:rPr>
                    <w:t>{xml_escape(k)}</w:t>
                  </w:r>
                </w:p>
              </w:tc>
              <w:tc>
                <w:tcPr>
                  <w:tcW w:w="6360" w:type="dxa"/>
                  <w:shd w:val="clear" w:color="auto" w:fill="FFFFFF"/>
                </w:tcPr>
                <w:p>
                  <w:pPr><w:spacing w:before="80" w:after="80"/></w:pPr>
                  <w:r>
                    <w:rPr><w:b/><w:sz w:val="20"/><w:color w:val="0F172A"/></w:rPr>
                    <w:t>{xml_escape(v)}</w:t>
                  </w:r>
                </w:p>
              </w:tc>
            </w:tr>
            ''')

        self.body_elements.append(f'''
        <w:tbl>
          <w:tblPr>
            <w:tblW w:w="9360" w:type="dxa"/>
            <w:jc w:val="center"/>
            <w:tblBorders>
              <w:top w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:left w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:bottom w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:right w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:insideH w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
              <w:insideV w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
            </w:tblBorders>
            <w:tblCellMar>
              <w:top w:w="120" w:type="dxa"/>
              <w:left w:w="160" w:type="dxa"/>
              <w:bottom w:w="120" w:type="dxa"/>
              <w:right w:w="160" w:type="dxa"/>
            </w:tblCellMar>
          </w:tblPr>
          {''.join(meta_rows)}
        </w:tbl>
        ''')

        # Confidentiality banner
        self.body_elements.append('''
        <w:p>
          <w:pPr>
            <w:spacing w:before="360" w:after="360"/>
            <w:jc w:val="center"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:i/>
              <w:sz w:val="18"/>
              <w:color w:val="64748B"/>
            </w:rPr>
            <w:t>CONFIDENTIAL &amp; PROPRIETARY — FOR ACCREDITED &amp; INSTITUTIONAL INVESTORS ONLY</w:t>
          </w:r>
        </w:p>
        ''')

    def add_h1(self, text):
        self.body_elements.append('''
        <w:p>
          <w:pPr>
            <w:spacing w:before="360" w:after="140"/>
            <w:pBdr>
              <w:bottom w:val="single" w:sz="12" w:space="4" w:color="991B1B"/>
            </w:pBdr>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:b/>
              <w:sz w:val="34"/>
              <w:color w:val="991B1B"/>
            </w:rPr>
            <w:t>''' + xml_escape(text) + '''</w:t>
          </w:r>
        </w:p>
        ''')

    def add_h2(self, text):
        self.body_elements.append('''
        <w:p>
          <w:pPr>
            <w:spacing w:before="240" w:after="100"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:b/>
              <w:sz w:val="26"/>
              <w:color w:val="0F172A"/>
            </w:rPr>
            <w:t>''' + xml_escape(text) + '''</w:t>
          </w:r>
        </w:p>
        ''')

    def add_h3(self, text):
        self.body_elements.append('''
        <w:p>
          <w:pPr>
            <w:spacing w:before="160" w:after="80"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:b/>
              <w:sz w:val="22"/>
              <w:color w:val="C9182B"/>
            </w:rPr>
            <w:t>''' + xml_escape(text) + '''</w:t>
          </w:r>
        </w:p>
        ''')

    def add_p(self, text, bold_prefix="", italic=False, color="334155"):
        prefix_xml = ""
        if bold_prefix:
            prefix_xml = f'''
            <w:r>
              <w:rPr>
                <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
                <w:b/>
                <w:sz w:val="21"/>
                <w:color w:val="0F172A"/>
              </w:rPr>
              <w:t xml:space="preserve">{xml_escape(bold_prefix)} </w:t>
            </w:r>
            '''

        self.body_elements.append(f'''
        <w:p>
          <w:pPr>
            <w:spacing w:before="40" w:after="100"/>
            <w:line w:line="276" w:lineRule="auto"/>
          </w:pPr>
          {prefix_xml}
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              {'<w:i/>' if italic else ''}
              <w:sz w:val="21"/>
              <w:color w:val="{color}"/>
            </w:rPr>
            <w:t>{xml_escape(text)}</w:t>
          </w:r>
        </w:p>
        ''')

    def add_bullet(self, bold_prefix, text):
        self.body_elements.append(f'''
        <w:p>
          <w:pPr>
            <w:ind w:left="480" w:hanging="240"/>
            <w:spacing w:before="30" w:after="70"/>
            <w:line w:line="260" w:lineRule="auto"/>
          </w:pPr>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:b/>
              <w:sz w:val="21"/>
              <w:color w:val="C9182B"/>
            </w:rPr>
            <w:t xml:space="preserve">▪ </w:t>
          </w:r>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:b/>
              <w:sz w:val="21"/>
              <w:color w:val="0F172A"/>
            </w:rPr>
            <w:t xml:space="preserve">{xml_escape(bold_prefix)}: </w:t>
          </w:r>
          <w:r>
            <w:rPr>
              <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>
              <w:sz w:val="21"/>
              <w:color w:val="334155"/>
            </w:rPr>
            <w:t>{xml_escape(text)}</w:t>
          </w:r>
        </w:p>
        ''')

    def add_callout(self, title, text, border_color="991B1B", bg_color="F8FAFC"):
        self.body_elements.append(f'''
        <w:tbl>
          <w:tblPr>
            <w:tblW w:w="9360" w:type="dxa"/>
            <w:jc w:val="center"/>
            <w:tblBorders>
              <w:top w:val="none"/>
              <w:left w:val="single" w:sz="24" w:space="0" w:color="{border_color}"/>
              <w:bottom w:val="none"/>
              <w:right w:val="none"/>
            </w:tblBorders>
            <w:tblCellMar>
              <w:top w:w="160" w:type="dxa"/>
              <w:left w:w="220" w:type="dxa"/>
              <w:bottom w:w="160" w:type="dxa"/>
              <w:right w:w="200" w:type="dxa"/>
            </w:tblCellMar>
          </w:tblPr>
          <w:tr>
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="9360" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="{bg_color}"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:spacing w:before="0" w:after="60"/></w:pPr>
                <w:r>
                  <w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="{border_color}"/></w:rPr>
                  <w:t>{xml_escape(title)}</w:t>
                </w:r>
              </w:p>
              <w:p>
                <w:pPr><w:spacing w:before="0" w:after="0"/><w:line w:line="260" w:lineRule="auto"/></w:pPr>
                <w:r>
                  <w:rPr><w:sz w:val="20"/><w:color w:val="334155"/></w:rPr>
                  <w:t>{xml_escape(text)}</w:t>
                </w:r>
              </w:p>
            </w:tc>
          </w:tr>
        </w:tbl>
        ''')

    def add_table(self, headers, rows, col_widths=None, align_right_cols=None, header_bg="991B1B"):
        if col_widths is None:
            w_each = self.content_width // len(headers)
            col_widths = [w_each] * len(headers)
            col_widths[-1] = self.content_width - sum(col_widths[:-1])
        if align_right_cols is None:
            align_right_cols = set()

        table_xml = [f'''
        <w:tbl>
          <w:tblPr>
            <w:tblW w:w="{self.content_width}" w:type="dxa"/>
            <w:jc w:val="center"/>
            <w:tblBorders>
              <w:top w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:left w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:bottom w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:right w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
              <w:insideH w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
              <w:insideV w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
            </w:tblBorders>
            <w:tblCellMar>
              <w:top w:w="120" w:type="dxa"/>
              <w:left w:w="140" w:type="dxa"/>
              <w:bottom w:w="120" w:type="dxa"/>
              <w:right w:w="140" w:type="dxa"/>
            </w:tblCellMar>
          </w:tblPr>
          <w:tblGrid>
        ''']
        for w in col_widths:
            table_xml.append(f'<w:gridCol w:w="{w}"/>')
        table_xml.append('</w:tblGrid>')

        # Header Row
        table_xml.append('<w:tr><w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>')
        for idx, (h, w) in enumerate(zip(headers, col_widths)):
            jc = "right" if idx in align_right_cols else "left"
            table_xml.append(f'''
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="{w}" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="{header_bg}"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="{jc}"/><w:spacing w:before="60" w:after="60"/></w:pPr>
                <w:r>
                  <w:rPr>
                    <w:b/>
                    <w:sz w:val="19"/>
                    <w:color w:val="FFFFFF"/>
                  </w:rPr>
                  <w:t>{xml_escape(h)}</w:t>
                </w:r>
              </w:p>
            </w:tc>
            ''')
        table_xml.append('</w:tr>')

        # Data Rows
        for r_idx, row in enumerate(rows):
            bg = "F8FAFC" if (r_idx % 2 == 1) else "FFFFFF"
            # Highlight totals or summary rows
            is_bold_row = False
            first_cell = str(row[0]).strip().lower()
            if any(term in first_cell for term in ["total", "ebitda", "gross profit", "net profit", "free cash", "take rate"]):
                is_bold_row = True
                if "ebitda" in first_cell or "net profit" in first_cell:
                    bg = "FEF2F2" # subtle light red for key metric

            table_xml.append('<w:tr><w:trPr><w:cantSplit/></w:trPr>')
            for idx, (c, w) in enumerate(zip(row, col_widths)):
                jc = "right" if idx in align_right_cols else "left"
                b_tag = "<w:b/>" if is_bold_row or idx == 0 else ""
                color_val = "0F172A" if (is_bold_row or idx == 0) else "334155"
                table_xml.append(f'''
                <w:tc>
                  <w:tcPr>
                    <w:tcW w:w="{w}" w:type="dxa"/>
                    <w:shd w:val="clear" w:color="auto" w:fill="{bg}"/>
                  </w:tcPr>
                  <w:p>
                    <w:pPr><w:jc w:val="{jc}"/><w:spacing w:before="50" w:after="50"/></w:pPr>
                    <w:r>
                      <w:rPr>
                        {b_tag}
                        <w:sz w:val="18"/>
                        <w:color w:val="{color_val}"/>
                      </w:rPr>
                      <w:t>{xml_escape(str(c))}</w:t>
                    </w:r>
                  </w:p>
                </w:tc>
                ''')
            table_xml.append('</w:tr>')

        table_xml.append('</w:tbl>')
        self.body_elements.append(''.join(table_xml))

    def save(self):
        content_types = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
</Types>'''

        rels = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>'''

        core_props = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <dc:title>Tweak Insight Logistics - Institutional Investor Memorandum</dc:title>
  <dc:subject>Full System Architecture, Operations &amp; 5-Year Financial Model</dc:subject>
  <dc:creator>Tweak Insight Logistics Executive Leadership</dc:creator>
  <cp:lastModifiedBy>Tweak Insight Logistics</cp:lastModifiedBy>
  <cp:revision>1</cp:revision>
</cp:coreProperties>'''

        app_props = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
  <Application>Microsoft Office Word</Application>
  <Company>Tweak Insight Logistics Ltd.</Company>
</Properties>'''

        styles = '''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault>
      <w:rPr>
        <w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>
        <w:sz w:val="21"/>
        <w:szCs w:val="21"/>
        <w:lang w:val="en-US"/>
      </w:rPr>
    </w:rPrDefault>
    <w:pPrDefault>
      <w:pPr>
        <w:spacing w:after="120" w:line="276" w:lineRule="auto"/>
      </w:pPr>
    </w:pPrDefault>
  </w:docDefaults>
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal">
    <w:name w:val="Normal"/>
    <w:qFormat/>
  </w:style>
</w:styles>'''

        doc_xml = f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:body>
    {''.join(self.body_elements)}
    <w:sectPr>
      <w:pgSz w:w="{self.page_width}" w:h="{self.page_height}"/>
      <w:pgMar w:top="{self.margin}" w:right="{self.margin}" w:bottom="{self.margin}" w:left="{self.margin}"/>
    </w:sectPr>
  </w:body>
</w:document>'''

        with zipfile.ZipFile(self.filename, 'w', zipfile.ZIP_DEFLATED) as z:
            z.writestr('[Content_Types].xml', content_types)
            z.writestr('_rels/.rels', rels)
            z.writestr('docProps/core.xml', core_props)
            z.writestr('docProps/app.xml', app_props)
            z.writestr('word/styles.xml', styles)
            z.writestr('word/document.xml', doc_xml)

        print(f"Generated {self.filename} ({os.path.getsize(self.filename):,} bytes)")

print("DocxBuilder initialized successfully")
