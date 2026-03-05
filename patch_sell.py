from pathlib import Path

path = Path('Modules/Pos/Resources/views/sell.blade.php')
content = path.read_text('utf-8')

# Search for the target header span
orig_str = '''        <div class="test">...</div>'''

replacement = '''        <div class="test-test">...</div>'''

if orig_str in content:
    path.write_text(content.replace(orig_str, replacement), 'utf-8')
    print("Patched!")
else:
    print("Target not found.")

