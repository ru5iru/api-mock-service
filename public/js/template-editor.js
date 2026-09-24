(function () {
    'use strict';

    var catalogPromise = null;
    var draggedSchemaRow = null;

    function catalog() {
        if (!catalogPromise) {
            catalogPromise = fetch('/api/faker-catalog', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            }).then(function (response) {
                if (!response.ok) throw new Error('Faker catalog could not be loaded.');
                return response.json();
            });
        }

        return catalogPromise;
    }

    function activeFragment(editor) {
        var before = editor.value.slice(0, editor.selectionStart);
        var match = before.match(/(\$|\{\{)([A-Za-z0-9_.]*)$/);
        if (!match) return null;
        var start = editor.selectionStart - match[0].length;
        if ((match[1] === '$' && before[start - 1] === '$') || (match[1] === '{{' && before[start - 1] === '\\')) {
            return null;
        }

        return { prefix: match[1], query: match[2], start: start };
    }

    function positionPopover(editor, popover) {
        var rect = editor.getBoundingClientRect();
        var width = Math.min(420, window.innerWidth - 32);
        var estimatedHeight = 260;
        var left = Math.max(16, Math.min(rect.left, window.innerWidth - width - 16));
        var below = rect.bottom + 6;
        var top = below + estimatedHeight <= window.innerHeight
            ? below
            : Math.max(16, rect.top - estimatedHeight - 6);
        popover.style.width = width + 'px';
        popover.style.left = left + 'px';
        popover.style.top = top + 'px';
    }

    function closeAutocomplete(root) {
        var popover = root?.querySelector('[data-template-autocomplete]');
        if (!popover) return;
        popover.hidden = true;
        popover.replaceChildren();
        root.querySelector('[data-template-editor]')?.setAttribute('aria-expanded', 'false');
    }

    function positionFakerPicker(picker) {
        var summary = picker.querySelector('summary');
        var panel = picker.querySelector('.faker-picker-panel');
        if (!summary || !panel) return;
        var rect = summary.getBoundingClientRect();
        var width = Math.min(480, window.innerWidth - 32);
        var height = Math.min(420, panel.scrollHeight || 420, window.innerHeight - 32);
        var left = Math.max(16, Math.min(rect.left, window.innerWidth - width - 16));
        var below = rect.bottom + 6;
        var top = below + height <= window.innerHeight
            ? below
            : Math.max(16, rect.top - height - 6);
        panel.style.position = 'fixed';
        panel.style.width = width + 'px';
        panel.style.maxHeight = height + 'px';
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }

    function insertMethod(editor, item, fragment) {
        var token = fragment.prefix === '{{' ? '{{' + item.id + '}}' : '$' + item.id;
        editor.setRangeText(token, fragment.start, editor.selectionStart, 'end');
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        editor.focus();
    }

    async function openAutocomplete(editor) {
        var root = editor.closest('[data-template-editor-root]');
        var popover = root?.querySelector('[data-template-autocomplete]');
        var fragment = activeFragment(editor);
        if (!popover || !fragment) {
            closeAutocomplete(root);
            return;
        }

        try {
            var items = (await catalog()).filter(function (item) {
                var haystack = [item.id].concat(item.aliases || []).join(' ').toLowerCase();
                return haystack.includes(fragment.query.toLowerCase());
            }).slice(0, 10);

            popover.replaceChildren();
            if (!items.length) {
                closeAutocomplete(root);
                return;
            }

            items.forEach(function (item, index) {
                var button = document.createElement('button');
                var label = document.createElement('span');
                var sample = document.createElement('small');
                button.type = 'button';
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', String(index === 0));
                button.dataset.autocompleteOption = '';
                button.title = 'Sample: ' + (typeof item.sample === 'string' ? item.sample : JSON.stringify(item.sample));
                label.textContent = item.id;
                sample.textContent = typeof item.sample === 'string' ? item.sample : JSON.stringify(item.sample);
                button.append(label, sample);
                button.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    insertMethod(editor, item, fragment);
                    closeAutocomplete(root);
                });
                popover.append(button);
            });

            positionPopover(editor, popover);
            popover.hidden = false;
            editor.setAttribute('aria-expanded', 'true');
        } catch (error) {
            closeAutocomplete(root);
            window.MockDeck?.toast?.('Faker method suggestions could not be loaded.', 'danger');
        }
    }

    function moveSelection(popover, direction) {
        var options = Array.from(popover.querySelectorAll('[data-autocomplete-option]'));
        if (!options.length) return;
        var current = Math.max(0, options.findIndex(function (option) { return option.getAttribute('aria-selected') === 'true'; }));
        var next = (current + direction + options.length) % options.length;
        options.forEach(function (option, index) { option.setAttribute('aria-selected', String(index === next)); });
        options[next].scrollIntoView({ block: 'nearest' });
    }

    document.addEventListener('input', function (event) {
        if (event.target.matches?.('[data-template-editor]')) openAutocomplete(event.target);

        var search = event.target.closest?.('[data-faker-search]');
        if (search) {
            var picker = search.closest('[data-faker-picker]');
            var query = search.value.trim().toLowerCase();
            picker.querySelectorAll('[data-faker-option]').forEach(function (option) {
                option.hidden = !option.dataset.search.includes(query);
            });
            picker.querySelectorAll('[data-faker-group]').forEach(function (group) {
                group.hidden = !group.querySelector('[data-faker-option]:not([hidden])');
            });
        }
    });

    document.addEventListener('toggle', function (event) {
        var picker = event.target.closest?.('details[data-faker-picker]');
        if (picker?.open) requestAnimationFrame(function () { positionFakerPicker(picker); });
    }, true);

    document.addEventListener('keydown', function (event) {
        var editor = event.target.closest?.('[data-template-editor]');
        if (editor) {
            var root = editor.closest('[data-template-editor-root]');
            var popover = root?.querySelector('[data-template-autocomplete]');
            if (!popover || popover.hidden) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                moveSelection(popover, event.key === 'ArrowDown' ? 1 : -1);
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                var selected = popover.querySelector('[aria-selected="true"]');
                if (selected) {
                    event.preventDefault();
                    selected.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                }
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeAutocomplete(root);
            }
            return;
        }

        var search = event.target.closest?.('[data-faker-search]');
        if (!search || !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
        event.preventDefault();
        var visible = Array.from(search.closest('[data-faker-picker]').querySelectorAll('[data-faker-option]:not([hidden])'));
        (event.key === 'ArrowDown' ? visible[0] : visible[visible.length - 1])?.focus();
    });

    document.addEventListener('keydown', function (event) {
        var option = event.target.closest?.('[data-faker-option]');
        if (!option || !['ArrowDown', 'ArrowUp', 'Home', 'End', 'Escape'].includes(event.key)) return;
        var picker = option.closest('[data-faker-picker]');
        var visible = Array.from(picker.querySelectorAll('[data-faker-option]:not([hidden])'));
        var index = visible.indexOf(option);
        if (event.key === 'Escape') {
            picker.removeAttribute('open');
            picker.querySelector('summary')?.focus();
            return;
        }
        event.preventDefault();
        var next = event.key === 'Home' ? 0 : event.key === 'End' ? visible.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length;
        visible[next]?.focus();
    });

    document.addEventListener('click', function (event) {
        document.querySelectorAll('[data-template-editor-root]').forEach(function (root) {
            if (!root.contains(event.target)) closeAutocomplete(root);
        });
    });

    document.addEventListener('dragstart', function (event) {
        var handle = event.target.closest?.('[data-schema-drag-handle]');
        var row = handle?.closest('[data-schema-row]');
        if (!row) return;
        draggedSchemaRow = {
            parent: row.dataset.schemaParent,
            index: Number(row.dataset.schemaIndex),
            element: row,
        };
        row.classList.add('schema-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.schemaIndex);
    });

    document.addEventListener('dragover', function (event) {
        var row = event.target.closest?.('[data-schema-row]');
        if (draggedSchemaRow && row?.dataset.schemaParent === draggedSchemaRow.parent) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
    });

    document.addEventListener('drop', function (event) {
        var target = event.target.closest?.('[data-schema-row]');
        if (!draggedSchemaRow || !target || target.dataset.schemaParent !== draggedSchemaRow.parent) return;
        event.preventDefault();
        var component = target.closest('[wire\\:id]');
        var destination = Number(target.dataset.schemaIndex);
        if (component && Number.isInteger(destination) && destination !== draggedSchemaRow.index) {
            window.Livewire?.find(component.getAttribute('wire:id'))?.call(
                'reorderSchemaRow',
                draggedSchemaRow.parent,
                draggedSchemaRow.index,
                destination,
            );
        }
    });

    document.addEventListener('dragend', function () {
        draggedSchemaRow?.element?.classList.remove('schema-dragging');
        draggedSchemaRow = null;
    });

    window.addEventListener('resize', function () {
        document.querySelectorAll('[data-template-autocomplete]:not([hidden])').forEach(function (popover) {
            var editor = popover.closest('[data-template-editor-root]')?.querySelector('[data-template-editor]');
            if (editor) positionPopover(editor, popover);
        });
        document.querySelectorAll('details[data-faker-picker][open]').forEach(positionFakerPicker);
    });

    window.addEventListener('scroll', function () {
        document.querySelectorAll('details[data-faker-picker][open]').forEach(positionFakerPicker);
    }, true);
}());
