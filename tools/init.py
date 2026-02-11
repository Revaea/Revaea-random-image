from __future__ import annotations

import json
from pathlib import Path


def main() -> None:
    repo_root = Path(__file__).resolve().parent.parent

    photos_dir = repo_root / "data" / "image" / "photos"
    portrait_dir = repo_root / "data" / "image" / "portrait"
    landscape_dir = repo_root / "data" / "image" / "landscape"

    for p in (photos_dir, portrait_dir, landscape_dir):
        p.mkdir(parents=True, exist_ok=True)

    image_lists_path = repo_root / "data" / "image_lists.json"
    if not image_lists_path.exists():
        payload = {"small_screens": [], "large_screens": []}
        image_lists_path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")

    print(f"Repo root: {repo_root}")
    print("Ensured dirs:")
    print(f"- {photos_dir}")
    print(f"- {portrait_dir}")
    print(f"- {landscape_dir}")
    print(f"image_lists.json: {image_lists_path}")


if __name__ == "__main__":
    main()
