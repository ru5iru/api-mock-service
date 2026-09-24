#!/usr/bin/env python3
"""Validate MockDeck's centralized visual tokens and WCAG contrast pairs."""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
TOKEN_FILE = ROOT / "public/css/tokens.css"
SOURCE_ROOTS = (ROOT / "public/css", ROOT / "public/js", ROOT / "resources/views")
COLOR_PATTERN = re.compile(r"#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(")
FONT_PATTERN = re.compile(r"(?:font-family|font)\s*:\s*([^;}]+)", re.IGNORECASE)


def source_files() -> list[Path]:
    files: list[Path] = []
    for source_root in SOURCE_ROOTS:
        for path in source_root.rglob("*"):
            if path.is_file() and path.suffix in {".css", ".js", ".php"} and path != TOKEN_FILE:
                files.append(path)

    return files


def lint_literals() -> list[str]:
    errors: list[str] = []
    for path in source_files():
        text = path.read_text(encoding="utf-8")
        for line_number, line in enumerate(text.splitlines(), 1):
            if COLOR_PATTERN.search(line):
                errors.append(f"{path.relative_to(ROOT)}:{line_number}: hard-coded colour")

        if path.suffix == ".css":
            for match in FONT_PATTERN.finditer(text):
                declaration = match.group(1).strip()
                if "var(--font-" not in declaration and declaration not in {"inherit", "initial", "unset"}:
                    line_number = text.count("\n", 0, match.start()) + 1
                    errors.append(
                        f"{path.relative_to(ROOT)}:{line_number}: font declaration must use --font-ui or --font-code",
                    )

    return errors


def parse_theme_tokens() -> tuple[dict[str, str], dict[str, str]]:
    css = TOKEN_FILE.read_text(encoding="utf-8")
    light_match = re.search(r":root,\s*\[data-theme=\"light\"\]\s*\{(.*?)\n\}", css, re.DOTALL)
    dark_match = re.search(r"\[data-theme=\"dark\"\]\s*\{(.*?)\n\}", css, re.DOTALL)
    if not light_match or not dark_match:
        raise ValueError("Light and dark token blocks are required.")

    token_pattern = re.compile(r"(--[\w-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;")
    light = dict(token_pattern.findall(light_match.group(1)))
    dark = {**light, **dict(token_pattern.findall(dark_match.group(1)))}

    return light, dark


def luminance(value: str) -> float:
    channels = [int(value[index : index + 2], 16) / 255 for index in (1, 3, 5)]
    linear = [channel / 12.92 if channel <= 0.04045 else ((channel + 0.055) / 1.055) ** 2.4 for channel in channels]

    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]


def contrast(first: str, second: str) -> float:
    lighter, darker = sorted((luminance(first), luminance(second)), reverse=True)

    return (lighter + 0.05) / (darker + 0.05)


def lint_contrast() -> list[str]:
    light, dark = parse_theme_tokens()
    text_pairs = [
        ("--text", "--bg"),
        ("--text", "--surface"),
        ("--text-muted", "--bg"),
        ("--text-muted", "--surface"),
        ("--placeholder", "--surface"),
        ("--on-accent", "--accent"),
        ("--on-accent", "--accent-hover"),
        ("--accent-link", "--surface"),
        ("--disabled-fg", "--disabled-bg"),
        ("--code-fg", "--code-bg"),
        ("--code-muted", "--code-bg"),
        ("--on-danger", "--danger-fill"),
        ("--on-danger", "--danger-fill-hover"),
        ("--success-fg", "--success-bg"),
        ("--warning-fg", "--warning-bg"),
        ("--danger-fg", "--danger-bg"),
        ("--info-fg", "--info-bg"),
        ("--method-get-fg", "--method-get-bg"),
        ("--method-post-fg", "--method-post-bg"),
        ("--method-put-fg", "--method-put-bg"),
    ]
    ui_pairs = [
        ("--border-strong", "--bg"),
        ("--border-strong", "--surface"),
        ("--focus-ring", "--bg"),
        ("--focus-ring", "--surface"),
        ("--success-border", "--success-bg"),
        ("--warning-border", "--warning-bg"),
        ("--danger-border", "--danger-bg"),
        ("--info-border", "--info-bg"),
        ("--method-get-border", "--method-get-bg"),
        ("--method-post-border", "--method-post-bg"),
        ("--method-put-border", "--method-put-bg"),
    ]
    errors: list[str] = []

    for theme_name, tokens in (("light", light), ("dark", dark)):
        for foreground, background in text_pairs:
            ratio = contrast(tokens[foreground], tokens[background])
            if ratio < 4.5:
                errors.append(f"{theme_name}: {foreground} on {background} is {ratio:.2f}:1; expected at least 4.5:1")
        for foreground, background in ui_pairs:
            ratio = contrast(tokens[foreground], tokens[background])
            if ratio < 3:
                errors.append(f"{theme_name}: {foreground} on {background} is {ratio:.2f}:1; expected at least 3:1")

    return errors


def main() -> int:
    errors = [*lint_literals(), *lint_contrast()]
    if errors:
        print("Design token validation failed:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)

        return 1

    print("Design token validation passed: no colour/font literals outside tokens; light and dark contrast pairs pass.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
