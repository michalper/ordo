#!/usr/bin/env python3
"""Renders USER-GUIDE.md into Ordo-User-Guide.pdf for Adobe Commerce Marketplace submission.

Supports the small markdown subset USER-GUIDE.md actually uses: #/##/### headings, paragraphs,
fenced code blocks, pipe tables, [text](url) links, and `code` spans. Not a general markdown
converter — edit USER-GUIDE.md, then re-run this script.
"""
import os
import re

from reportlab.lib import colors
from reportlab.lib.pagesizes import LETTER
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import inch
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, Preformatted, ListFlowable, ListItem,
)

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "USER-GUIDE.md")
OUT = os.path.join(HERE, "Ordo-User-Guide.pdf")

LINK_RE = re.compile(r"\[([^\]]+)\]\(([^)]+)\)")
CODE_RE = re.compile(r"`([^`]+)`")
BOLD_RE = re.compile(r"\*\*([^*]+)\*\*")


def inline(text: str) -> str:
    text = LINK_RE.sub(r'<link href="\2" color="#1a5fb4"><u>\1</u></link>', text)
    text = CODE_RE.sub(r'<font face="Courier" size="9">\1</font>', text)
    text = BOLD_RE.sub(r"<b>\1</b>", text)
    return text


def build_styles():
    styles = getSampleStyleSheet()
    styles.add(ParagraphStyle("OrdoTitle", parent=styles["Title"], fontSize=22, spaceAfter=4))
    styles.add(ParagraphStyle("OrdoSubtitle", parent=styles["Normal"], fontSize=13,
                               textColor=colors.HexColor("#555555"), spaceAfter=18))
    styles.add(ParagraphStyle("OrdoH1", parent=styles["Heading1"], fontSize=15, spaceBefore=18,
                               spaceAfter=8, textColor=colors.HexColor("#1a1a1a")))
    styles.add(ParagraphStyle("OrdoH2", parent=styles["Heading2"], fontSize=12.5, spaceBefore=12,
                               spaceAfter=6, textColor=colors.HexColor("#1a1a1a")))
    styles.add(ParagraphStyle("OrdoBody", parent=styles["Normal"], fontSize=10, leading=14,
                               spaceAfter=8))
    styles.add(ParagraphStyle("OrdoCell", parent=styles["Normal"], fontSize=8.5, leading=11))
    styles.add(ParagraphStyle("OrdoCellHead", parent=styles["Normal"], fontSize=8.5, leading=11,
                               textColor=colors.white, fontName="Helvetica-Bold"))
    return styles


def parse_table(lines, i, styles):
    rows = []
    while i < len(lines) and lines[i].strip().startswith("|"):
        row = lines[i].strip()
        if not re.match(r"^\|[\s:|-]+\|$", row):
            cells = [c.strip() for c in row.strip("|").split("|")]
            rows.append(cells)
        i += 1
    if not rows:
        raise ValueError("markdown table with no rows — check USER-GUIDE.md around the table markers")
    header, body = rows[0], rows[1:]
    data = [[Paragraph(inline(c), styles["OrdoCellHead"]) for c in header]]
    for r in body:
        data.append([Paragraph(inline(c), styles["OrdoCell"]) for c in r])
    col_count = len(header)
    widths = [6.5 * inch / col_count] * col_count
    table = Table(data, colWidths=widths, repeatRows=1)
    table.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#1a5fb4")),
        ("GRID", (0, 0), (-1, -1), 0.5, colors.HexColor("#cccccc")),
        ("ROWBACKGROUNDS", (0, 1), (-1, -1), [colors.white, colors.HexColor("#f4f6f8")]),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("TOPPADDING", (0, 0), (-1, -1), 4),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
    ]))
    return table, i


def build_story(lines, styles):
    story = []
    i = 0
    while i < len(lines):
        stripped = lines[i].strip()

        if stripped.startswith("```"):
            i += 1
            code_lines = []
            while i < len(lines) and not lines[i].strip().startswith("```"):
                code_lines.append(lines[i].rstrip("\n"))
                i += 1
            i += 1
            story.append(Preformatted("\n".join(code_lines), styles["Code"]))
            story.append(Spacer(1, 8))
            continue

        if stripped.startswith("|"):
            table, i = parse_table(lines, i, styles)
            story.append(table)
            story.append(Spacer(1, 10))
            continue

        if stripped.startswith("### "):
            story.append(Paragraph(inline(stripped[4:]), styles["OrdoH2"]))
            i += 1
            continue

        if stripped.startswith("## "):
            story.append(Paragraph(inline(stripped[3:]), styles["OrdoH1"]))
            i += 1
            continue

        if stripped.startswith("# "):
            story.append(Paragraph(inline(stripped[2:]), styles["OrdoTitle"]))
            i += 1
            continue

        if stripped.startswith("- "):
            items = []
            while i < len(lines) and lines[i].strip().startswith("- "):
                items.append(ListItem(Paragraph(inline(lines[i].strip()[2:]), styles["OrdoBody"]),
                                       leftIndent=12))
                i += 1
            story.append(ListFlowable(items, bulletType="bullet", start="•", leftIndent=10))
            story.append(Spacer(1, 6))
            continue

        if stripped == "":
            i += 1
            continue

        # Plain paragraph — join wrapped lines until a blank line or a structural line.
        buf = [stripped]
        i += 1
        while i < len(lines):
            nxt = lines[i].strip()
            if (nxt == "" or nxt.startswith(("#", "```", "|", "- "))):
                break
            buf.append(nxt)
            i += 1
        # The second line after the H1 title doubles as the subtitle.
        style = "OrdoSubtitle" if len(story) == 1 else "OrdoBody"
        story.append(Paragraph(inline(" ".join(buf)), styles[style]))

    return story


def main():
    with open(SRC, encoding="utf-8") as f:
        lines = f.readlines()

    styles = build_styles()
    doc = SimpleDocTemplate(
        OUT, pagesize=LETTER,
        leftMargin=0.85 * inch, rightMargin=0.85 * inch,
        topMargin=0.75 * inch, bottomMargin=0.75 * inch,
        title="Ordo User & Setup Guide", author="Michal Per",
    )
    story = build_story(lines, styles)
    doc.build(story)
    print(f"Wrote {OUT}")


if __name__ == "__main__":
    main()
