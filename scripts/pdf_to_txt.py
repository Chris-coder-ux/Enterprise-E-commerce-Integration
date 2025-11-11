#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Conversor de PDF a TXT (basado en pdfminer.six)

Uso básico:
  python scripts/pdf_to_txt.py /ruta/al/archivo.pdf

Uso múltiple / carpeta de salida:
  python scripts/pdf_to_txt.py archivo1.pdf archivo2.pdf --out-dir salida/

Opciones:
  --password <pwd>     Contraseña del PDF (si está protegido)
  --pages 1-3,5,9      Rango de páginas a extraer (1-indexed)
  --encoding utf-8     Codificación del TXT (por defecto utf-8)
  --overwrite          Sobrescribir si existe el TXT
  --preserve-layout    Intenta conservar layout (mejor para tablas simples)
"""
from __future__ import annotations
import argparse
import os
import sys
from typing import List, Tuple, Optional

try:
    from pdfminer.high_level import extract_text
    from pdfminer.layout import LAParams
except Exception as e:
    sys.stderr.write("[ERROR] Falta la dependencia pdfminer.six. Instálala con: pip install pdfminer.six\n")
    raise


def parse_pages(pages_arg: Optional[str]) -> Optional[List[int]]:
    if not pages_arg:
        return None
    pages: List[int] = []
    for part in pages_arg.split(','):
        part = part.strip()
        if '-' in part:
            start_s, end_s = part.split('-', 1)
            try:
                start = int(start_s)
                end = int(end_s)
            except ValueError:
                raise ValueError(f"Rango de páginas inválido: {part}")
            if start <= 0 or end < start:
                raise ValueError(f"Rango de páginas inválido: {part}")
            pages.extend(list(range(start, end + 1)))
        else:
            try:
                p = int(part)
            except ValueError:
                raise ValueError(f"Página inválida: {part}")
            if p <= 0:
                raise ValueError(f"Página inválida: {part}")
            pages.append(p)
    # El usuario usa 1-index; pdfminer también acepta page_numbers 0-indexed?
    # pdfminer.high_level.extract_text usa page_numbers 0-indexed.
    # Convertimos a 0-index internamente.
    return [p - 1 for p in pages]


def derive_output_path(pdf_path: str, out_dir: Optional[str]) -> str:
    base_name = os.path.splitext(os.path.basename(pdf_path))[0]
    out_dir = out_dir or os.path.dirname(os.path.abspath(pdf_path))
    return os.path.join(out_dir, f"{base_name}.txt")


def pdf_to_txt(
    pdf_path: str,
    out_path: Optional[str] = None,
    password: Optional[str] = None,
    page_numbers: Optional[List[int]] = None,
    encoding: str = "utf-8",
    overwrite: bool = False,
    preserve_layout: bool = False,
) -> Tuple[bool, str]:
    if not os.path.isfile(pdf_path):
        return False, f"No existe el archivo PDF: {pdf_path}"

    if out_path is None:
        out_path = derive_output_path(pdf_path, None)

    # Crear directorio de salida si no existe
    os.makedirs(os.path.dirname(os.path.abspath(out_path)), exist_ok=True)

    if os.path.exists(out_path) and not overwrite:
        return False, f"Salida ya existe, usa --overwrite: {out_path}"

    laparams = None
    if preserve_layout:
        # Ajustes típicos para intentar conservar layout básico
        laparams = LAParams(
            line_margin=0.1,
            char_margin=2.0,
            word_margin=0.1,
            boxes_flow=None,
            detect_vertical=False,
            all_texts=True,
        )

    try:
        text = extract_text(
            pdf_path,
            password=password,
            page_numbers=page_numbers,
            laparams=laparams,
        )
        with open(out_path, 'w', encoding=encoding, errors='ignore') as f:
            f.write(text or '')
        return True, out_path
    except Exception as e:
        return False, f"Error extrayendo texto: {e}"


def main(argv: List[str]) -> int:
    parser = argparse.ArgumentParser(description="Convertir PDF a TXT usando pdfminer.six")
    parser.add_argument('pdfs', nargs='+', help='Rutas de archivo PDF a convertir')
    parser.add_argument('--out', dest='out', help='Ruta de salida (si un único PDF)')
    parser.add_argument('--out-dir', dest='out_dir', help='Directorio de salida (si múltiples PDFs)')
    parser.add_argument('--password', dest='password', default=None, help='Contraseña del PDF (si aplica)')
    parser.add_argument('--pages', dest='pages', default=None, help='Rango de páginas, ej: 1-3,5,9')
    parser.add_argument('--encoding', dest='encoding', default='utf-8', help='Codificación del TXT (por defecto utf-8)')
    parser.add_argument('--overwrite', action='store_true', help='Sobrescribir si el archivo de salida existe')
    parser.add_argument('--preserve-layout', action='store_true', help='Intentar preservar layout (tablas simples)')

    args = parser.parse_args(argv)

    page_numbers = parse_pages(args.pages) if args.pages else None

    if len(args.pdfs) > 1 and args.out:
        print("[AVISO] --out se ignora con múltiples PDFs; usa --out-dir", file=sys.stderr)

    results = []
    for i, pdf in enumerate(args.pdfs):
        if len(args.pdfs) == 1:
            out_path = args.out or derive_output_path(pdf, args.out_dir)
        else:
            out_path = derive_output_path(pdf, args.out_dir)

        ok, msg = pdf_to_txt(
            pdf_path=pdf,
            out_path=out_path,
            password=args.password,
            page_numbers=page_numbers,
            encoding=args.encoding,
            overwrite=args.overwrite,
            preserve_layout=args.preserve_layout,
        )
        results.append((ok, msg))
        status = 'OK' if ok else 'ERROR'
        print(f"[{status}] {pdf} -> {msg}")

    # Código de salida: 0 si todos OK; 1 si alguno falló
    return 0 if all(ok for ok, _ in results) else 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
