#!/bin/bash

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WATCH_DIR="$ROOT_DIR/data/image/photos"
CLASSIFY_SCRIPT="$ROOT_DIR/tools/classify.py"

# 使用 inotifywait 监听文件夹的改名事件
inotifywait -m -e close_write,move --format "%w%f" "$WATCH_DIR" | while read NEWFILE
do
    echo "File change detected: $NEWFILE"
    # 直接延迟10秒后执行 classify.py
    echo "Waiting 10 seconds to process..."
    sleep 10
    echo "Running classify.py..."
    python3 "$CLASSIFY_SCRIPT"
done
