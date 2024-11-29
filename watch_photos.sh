#!/bin/bash
WATCH_DIR="/www/wwwroot/random-pic-api/photos"
CLASSIFY_SCRIPT="/www/wwwroot/random-pic-api/classify.py"

# 使用 inotifywait 监听文件夹的改名事件
inotifywait -m -e close_write,move --format "%w%f" "$WATCH_DIR" | while read NEWFILE
do
    echo "File change detected: $NEWFILE"
    # 直接延迟10秒后执行 classify.py
    echo "Waiting 30 seconds to process..."
    sleep 10
    echo "Running classify.py..."
    python3 "$CLASSIFY_SCRIPT"
done
