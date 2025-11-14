#!/bin/bash

if [ ! -f index.php ]; then
    echo "index.php missing. Same folder me rakho."
    exit 1
fi

echo "Server running → http://localhost:8080"
php -S localhost:8080 index.php
