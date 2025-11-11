#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script corregido para convertir Manual_Usuario_Dashboard.md a formato .odp válido para LibreOffice Impress
"""

import os
import zipfile
import xml.etree.ElementTree as ET
from datetime import datetime
import re
import shutil
import html

class FixedManualToODPConverter:
    def __init__(self, markdown_file, output_file):
        self.markdown_file = markdown_file
        self.output_file = output_file
        self.slides = []
        
    def read_markdown(self):
        """Lee el archivo markdown y lo procesa"""
        with open(self.markdown_file, 'r', encoding='utf-8') as f:
            content = f.read()
        return content
    
    def escape_xml(self, text):
        """Escapa caracteres especiales para XML"""
        if not text:
            return ""
        # Escapar caracteres XML especiales
        text = html.escape(text, quote=False)
        # Reemplazar caracteres problemáticos
        text = text.replace('&', '&amp;')
        text = text.replace('<', '&lt;')
        text = text.replace('>', '&gt;')
        text = text.replace('"', '&quot;')
        text = text.replace("'", '&apos;')
        return text
    
    def parse_content(self, content):
        """Parsea el contenido markdown y lo organiza en slides"""
        lines = content.split('\n')
        current_slide = None
        current_content = []
        
        for line in lines:
            line = line.strip()
            
            # Detectar títulos principales (##)
            if line.startswith('## ') and not line.startswith('### '):
                if current_slide:
                    current_slide['content'] = current_content
                    self.slides.append(current_slide)
                
                title = line[3:].strip()
                # Limpiar emojis y caracteres especiales para el título
                clean_title = re.sub(r'[^\w\s\-]', '', title)
                
                current_slide = {
                    'title': clean_title,
                    'content': []
                }
                current_content = []
                
            elif current_slide and line:
                # Procesar contenido de la slide
                if line.startswith('### '):
                    current_content.append({
                        'type': 'subtitle',
                        'text': line[4:].strip()
                    })
                elif line.startswith('- '):
                    current_content.append({
                        'type': 'bullet',
                        'text': line[2:].strip()
                    })
                elif line.startswith('**') and line.endswith('**'):
                    current_content.append({
                        'type': 'bold',
                        'text': line[2:-2].strip()
                    })
                elif line.startswith('|') and '|' in line[1:]:
                    # Tabla - simplificar para presentación
                    if '|' in line and not line.startswith('|---'):
                        cells = [cell.strip() for cell in line.split('|')[1:-1]]
                        if cells and cells[0]:  # No es línea separadora
                            current_content.append({
                                'type': 'text',
                                'text': ' | '.join(cells)
                            })
                elif line and not line.startswith('---'):
                    current_content.append({
                        'type': 'text',
                        'text': line
                    })
        
        # Agregar la última slide
        if current_slide:
            current_slide['content'] = current_content
            self.slides.append(current_slide)
    
    def create_fixed_odp(self):
        """Crea un archivo ODP con XML válido"""
        # Crear directorio temporal
        temp_dir = 'temp_odp_fixed'
        if os.path.exists(temp_dir):
            shutil.rmtree(temp_dir)
        os.makedirs(temp_dir, exist_ok=True)
        
        # Crear estructura de directorios
        os.makedirs(f'{temp_dir}/META-INF', exist_ok=True)
        
        # Crear mimetype
        with open(f'{temp_dir}/mimetype', 'w', encoding='utf-8') as f:
            f.write('application/vnd.oasis.opendocument.presentation')
        
        # Crear META-INF/manifest.xml
        self.create_manifest(temp_dir)
        
        # Crear content.xml
        self.create_content_xml(temp_dir)
        
        # Crear styles.xml
        self.create_styles_xml(temp_dir)
        
        # Crear meta.xml
        self.create_meta_xml(temp_dir)
        
        return temp_dir
    
    def create_manifest(self, temp_dir):
        """Crea el archivo manifest.xml"""
        manifest_content = '''<?xml version="1.0" encoding="UTF-8"?>
<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0">
  <manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.presentation"/>
  <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
  <manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
  <manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
</manifest:manifest>'''
        
        with open(f'{temp_dir}/META-INF/manifest.xml', 'w', encoding='utf-8') as f:
            f.write(manifest_content)
    
    def create_content_xml(self, temp_dir):
        """Crea el archivo content.xml con XML válido"""
        content_lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"',
            '                        xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"',
            '                        xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"',
            '                        xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"',
            '                        xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"',
            '                        xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"',
            '                        xmlns:xlink="http://www.w3.org/1999/xlink"',
            '                        xmlns:dc="http://purl.org/dc/elements/1.1/"',
            '                        xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"',
            '                        xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"',
            '                        xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"',
            '                        xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0"',
            '                        xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0"',
            '                        xmlns:math="http://www.w3.org/1998/Math/MathML"',
            '                        xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0"',
            '                        xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0"',
            '                        xmlns:ooo="http://openoffice.org/2004/office"',
            '                        xmlns:ooow="http://openoffice.org/2004/writer"',
            '                        xmlns:oooc="http://openoffice.org/2004/calc"',
            '                        xmlns:dom="http://www.w3.org/2001/xml-events"',
            '                        xmlns:xforms="http://www.w3.org/2002/xforms"',
            '                        xmlns:xsd="http://www.w3.org/2001/XMLSchema"',
            '                        xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"',
            '                        xmlns:rpt="http://openoffice.org/2005/report"',
            '                        xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2"',
            '                        xmlns:xhtml="http://www.w3.org/1999/xhtml"',
            '                        xmlns:grddl="http://www.w3.org/2003/g/data-view#"',
            '                        xmlns:tableooo="http://openoffice.org/2009/table"',
            '                        xmlns:field="urn:oasis:names:tc:opendocument:xmlns:field:1.0"',
            '                        xmlns:formx="urn:oasis:names:tc:opendocument:xmlns:form:1.0"',
            '                        xmlns:css3t="http://www.w3.org/TR/css3-text/"',
            '                        office:version="1.2">',
            '  <office:scripts/>',
            '  <office:font-face-decls/>',
            '  <office:automatic-styles>',
            '    <style:style style:name="dp1" style:family="drawing-page">',
            '      <style:drawing-page-properties draw:background-size="full" draw:fill="none"/>',
            '    </style:style>',
            '    <style:style style:name="gr1" style:family="graphic" style:parent-style-name="standard">',
            '      <style:graphic-properties draw:stroke="none" draw:fill="none"/>',
            '    </style:style>',
            '    <style:style style:name="gr2" style:family="graphic" style:parent-style-name="standard">',
            '      <style:graphic-properties draw:stroke="none" draw:fill="none"/>',
            '    </style:style>',
            '    <style:style style:name="gr3" style:family="graphic" style:parent-style-name="standard">',
            '      <style:graphic-properties draw:stroke="none" draw:fill="none"/>',
            '    </style:style>',
            '    <style:style style:name="P1" style:family="paragraph" style:parent-style-name="standard">',
            '      <style:paragraph-properties fo:text-align="center" fo:margin-top="0.423cm" fo:margin-bottom="0.212cm"/>',
            '      <style:text-properties fo:font-size="24pt" style:font-size-asian="24pt" style:font-size-complex="24pt" fo:font-weight="bold"/>',
            '    </style:style>',
            '    <style:style style:name="P2" style:family="paragraph" style:parent-style-name="standard">',
            '      <style:paragraph-properties fo:margin-top="0.212cm" fo:margin-bottom="0.212cm"/>',
            '      <style:text-properties fo:font-size="18pt" style:font-size-asian="18pt" style:font-size-complex="18pt" fo:font-weight="bold"/>',
            '    </style:style>',
            '    <style:style style:name="P3" style:family="paragraph" style:parent-style-name="standard">',
            '      <style:paragraph-properties fo:margin-top="0.106cm" fo:margin-bottom="0.106cm"/>',
            '      <style:text-properties fo:font-size="12pt" style:font-size-asian="12pt" style:font-size-complex="12pt"/>',
            '    </style:style>',
            '  </office:automatic-styles>',
            '  <office:master-styles>',
            '    <style:master-page style:name="Standard" style:page-layout-name="AL1T0">',
            '      <style:header/>',
            '      <style:footer/>',
            '    </style:master-page>',
            '  </office:master-styles>',
            '  <office:body>',
            '    <office:presentation>'
        ]
        
        # Agregar slides
        for i, slide in enumerate(self.slides):
            content_lines.extend(self.create_slide_xml_lines(slide, i))
        
        content_lines.extend([
            '    </office:presentation>',
            '  </office:body>',
            '</office:document-content>'
        ])
        
        with open(f'{temp_dir}/content.xml', 'w', encoding='utf-8') as f:
            f.write('\n'.join(content_lines))
    
    def create_slide_xml_lines(self, slide_data, slide_number):
        """Crea líneas XML para una slide individual"""
        lines = []
        
        # Escapar el título
        escaped_title = self.escape_xml(slide_data['title'])
        
        lines.append(f'      <draw:page draw:name="Slide{slide_number + 1}" draw:style-name="dp1" draw:master-page-name="Standard">')
        
        # Título de la slide
        lines.append(f'        <draw:frame draw:style-name="gr1" draw:text-style-name="P1" draw:layer="layout" svg:width="25.199cm" svg:height="3.506cm" svg:x="1.4cm" svg:y="0.3cm">')
        lines.append('          <draw:text-box>')
        lines.append(f'            <text:p text:style-name="P1">{escaped_title}</text:p>')
        lines.append('          </draw:text-box>')
        lines.append('        </draw:frame>')
        
        # Agregar contenido
        content_y = 4.0
        content_count = 0
        for content_item in slide_data['content']:
            if content_count >= 8:  # Limitar contenido por slide
                break
            
            # Verificar que el contenido tenga texto
            if 'text' not in content_item or not content_item['text']:
                continue
                
            escaped_text = self.escape_xml(content_item['text'])
            
            if content_item['type'] == 'subtitle':
                content_y += 0.8
                lines.append(f'        <draw:frame draw:style-name="gr2" draw:text-style-name="P2" draw:layer="layout" svg:width="25.199cm" svg:height="0.6cm" svg:x="1.4cm" svg:y="{content_y:.1f}cm">')
                lines.append('          <draw:text-box>')
                lines.append(f'            <text:p text:style-name="P2">{escaped_text}</text:p>')
                lines.append('          </draw:text-box>')
                lines.append('        </draw:frame>')
                content_count += 1
                
            elif content_item['type'] == 'bullet':
                content_y += 0.5
                lines.append(f'        <draw:frame draw:style-name="gr3" draw:text-style-name="P3" draw:layer="layout" svg:width="25.199cm" svg:height="0.5cm" svg:x="2.0cm" svg:y="{content_y:.1f}cm">')
                lines.append('          <draw:text-box>')
                lines.append(f'            <text:p text:style-name="P3">• {escaped_text}</text:p>')
                lines.append('          </draw:text-box>')
                lines.append('        </draw:frame>')
                content_count += 1
                
            elif content_item['type'] == 'text':
                content_y += 0.4
                lines.append(f'        <draw:frame draw:style-name="gr3" draw:text-style-name="P3" draw:layer="layout" svg:width="25.199cm" svg:height="0.4cm" svg:x="1.4cm" svg:y="{content_y:.1f}cm">')
                lines.append('          <draw:text-box>')
                lines.append(f'            <text:p text:style-name="P3">{escaped_text}</text:p>')
                lines.append('          </draw:text-box>')
                lines.append('        </draw:frame>')
                content_count += 1
        
        lines.append('      </draw:page>')
        return lines
    
    def create_styles_xml(self, temp_dir):
        """Crea el archivo styles.xml"""
        styles_content = '''<?xml version="1.0" encoding="UTF-8"?>
<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" 
                       xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" 
                       xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" 
                       xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" 
                       xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" 
                       xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" 
                       xmlns:xlink="http://www.w3.org/1999/xlink" 
                       xmlns:dc="http://purl.org/dc/elements/1.1/" 
                       xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" 
                       xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" 
                       xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" 
                       xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" 
                       xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" 
                       xmlns:math="http://www.w3.org/1998/Math/MathML" 
                       xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" 
                       xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" 
                       xmlns:ooo="http://openoffice.org/2004/office" 
                       xmlns:ooow="http://openoffice.org/2004/writer" 
                       xmlns:oooc="http://openoffice.org/2004/calc" 
                       xmlns:dom="http://www.w3.org/2001/xml-events" 
                       xmlns:xforms="http://www.w3.org/2002/xforms" 
                       xmlns:xsd="http://www.w3.org/2001/XMLSchema" 
                       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
                       xmlns:rpt="http://openoffice.org/2005/report" 
                       xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" 
                       xmlns:xhtml="http://www.w3.org/1999/xhtml" 
                       xmlns:grddl="http://www.w3.org/2003/g/data-view#" 
                       xmlns:tableooo="http://openoffice.org/2009/table" 
                       xmlns:field="urn:oasis:names:tc:opendocument:xmlns:field:1.0" 
                       xmlns:formx="urn:oasis:names:tc:opendocument:xmlns:form:1.0" 
                       xmlns:css3t="http://www.w3.org/TR/css3-text/" 
                       office:version="1.2">
  <office:font-face-decls/>
  <office:automatic-styles/>
  <office:master-styles/>
</office:document-styles>'''
        
        with open(f'{temp_dir}/styles.xml', 'w', encoding='utf-8') as f:
            f.write(styles_content)
    
    def create_meta_xml(self, temp_dir):
        """Crea el archivo meta.xml"""
        meta_content = f'''<?xml version="1.0" encoding="UTF-8"?>
<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" 
                      xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" 
                      xmlns:dc="http://purl.org/dc/elements/1.1/" 
                      xmlns:xlink="http://www.w3.org/1999/xlink" 
                      office:version="1.2">
  <office:meta>
    <dc:title>Manual de Usuario - Dashboard Mi Integración API</dc:title>
    <dc:description>Manual completo del Dashboard del plugin Mi Integración API</dc:description>
    <dc:creator>Sistema de Conversión Automática</dc:creator>
    <dc:date>{datetime.now().isoformat()}</dc:date>
  </office:meta>
</office:document-meta>'''
        
        with open(f'{temp_dir}/meta.xml', 'w', encoding='utf-8') as f:
            f.write(meta_content)
    
    def create_odp_file(self, temp_dir):
        """Crea el archivo .odp final"""
        with zipfile.ZipFile(self.output_file, 'w', zipfile.ZIP_DEFLATED) as odp_file:
            # Agregar mimetype sin comprimir
            odp_file.write(f'{temp_dir}/mimetype', 'mimetype')
            
            # Agregar resto de archivos
            for root, dirs, files in os.walk(temp_dir):
                for file in files:
                    if file != 'mimetype':
                        file_path = os.path.join(root, file)
                        arc_path = os.path.relpath(file_path, temp_dir)
                        odp_file.write(file_path, arc_path)
    
    def cleanup(self, temp_dir):
        """Limpia archivos temporales"""
        if os.path.exists(temp_dir):
            shutil.rmtree(temp_dir)
    
    def convert(self):
        """Ejecuta la conversión completa"""
        print("Leyendo archivo markdown...")
        content = self.read_markdown()
        
        print("Parseando contenido...")
        self.parse_content(content)
        
        print(f"Se encontraron {len(self.slides)} slides")
        
        print("Creando estructura ODP corregida...")
        temp_dir = self.create_fixed_odp()
        
        print("Generando archivo ODP...")
        self.create_odp_file(temp_dir)
        
        print("Limpiando archivos temporales...")
        self.cleanup(temp_dir)
        
        print(f"Conversión completada: {self.output_file}")

def main():
    input_file = '/home/christian/Escritorio/Verial/Manual_Usuario_Dashboard.md'
    output_file = '/home/christian/Escritorio/Verial/Manual_Usuario_Dashboard.odp'
    
    converter = FixedManualToODPConverter(input_file, output_file)
    converter.convert()

if __name__ == '__main__':
    main()
