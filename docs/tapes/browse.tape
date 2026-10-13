# Browse recent events: search, every field of one event, then its trace.
# Render from the repository root: vhs docs/tapes/browse.tape

Output docs/images/browse.gif

Set Shell bash
Set Theme "Catppuccin Mocha"
Set FontSize 14
Set Width 1000
Set Height 820
Set Padding 18
Set Framerate 15
Set TypingSpeed 50ms

# `php artisan` runs the workbench app; a fresh log, seeded by the demos.
Hide
Type `export PS1='\[\e[1;35m\]$\[\e[0m\] '; php() { if [ "$1" = artisan ]; then shift; command php vendor/bin/testbench "$@"; else command php "$@"; fi; }`
Enter
Type `cd vendor/orchestra/testbench-core/laravel/storage/logs && rm -f jot-20*.log && touch jot-$(date -u +%F).log && cd - >/dev/null`
Enter
Type `for s in booking login job-failed login-failed error job-sync; do php artisan jot:demo $s >/dev/null 2>&1; done; clear`
Enter
Wait /^\$$/
Sleep 500ms
Show

Type "php artisan jot:query"
Sleep 300ms
Enter
Sleep 2s
Type "/"
Sleep 300ms
Type "fail"
Sleep 1200ms
Enter
Sleep 500ms
Down
Sleep 800ms
Enter
Sleep 2500ms
Down
Sleep 600ms
Enter
Sleep 1500ms
Down
Sleep 1s
Enter
Sleep 3s
Down 2
Sleep 600ms
Enter
Sleep 1200ms
