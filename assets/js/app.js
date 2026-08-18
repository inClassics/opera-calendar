document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = window.SECTION_SCHEDULE?.csrfToken || '';

    const renderCell = (cell) => {
        const status = cell.dataset.status || '';
        cell.classList.remove('available', 'unavailable');
        cell.textContent = '';

        if (status === 'available') {
            cell.textContent = '×';
            cell.classList.add('available');
        } else if (status === 'unavailable') {
            cell.textContent = '•';
            cell.classList.add('unavailable');
        }
    };

    document.querySelectorAll('.availability-cell').forEach((cell) => {
        renderCell(cell);
        if (!cell.classList.contains('editable')) return;

        cell.addEventListener('click', async () => {
            if (cell.dataset.saving === '1') return;

            const previousStatus = cell.dataset.status || '';
            const nextStatus = previousStatus === '' ? 'available' : previousStatus === 'available' ? 'unavailable' : '';

            cell.dataset.status = nextStatus;
            renderCell(cell);
            cell.dataset.saving = '1';

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('user_id', cell.dataset.userId);
            formData.append('date', cell.dataset.date);
            formData.append('period', cell.dataset.period);
            formData.append('status', nextStatus);

            try {
                const response = await fetch('ajax/update-availability.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Could not save availability.');
            } catch (error) {
                cell.dataset.status = previousStatus;
                renderCell(cell);
                alert(error.message);
            } finally {
                cell.dataset.saving = '0';
            }
        });
    });

    document.querySelectorAll('.activity-editable').forEach((cell) => {
        cell.addEventListener('click', () => {
            if (cell.querySelector('input')) return;

            const currentText = cell.textContent.trim();
            const input = document.createElement('input');
            input.type = 'text';
            input.value = currentText;
            input.maxLength = 255;
            input.className = 'activity-input';

            cell.textContent = '';
            cell.appendChild(input);
            input.focus();
            input.select();

            let finished = false;

            const restore = () => {
                if (finished) return;
                finished = true;
                cell.textContent = currentText;
            };

            const save = async () => {
                if (finished) return;
                finished = true;
                const newText = input.value.trim();

                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('date', cell.dataset.date);
                formData.append('period', cell.dataset.period);
                formData.append('activity', newText);

                try {
                    const response = await fetch('ajax/update-activity.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.message || 'Could not save activity.');
                    cell.textContent = result.activity;
                } catch (error) {
                    cell.textContent = currentText;
                    alert(error.message);
                }
            };

            input.addEventListener('blur', save, { once: true });
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') input.blur();
                if (event.key === 'Escape') {
                    event.preventDefault();
                    restore();
                }
            });
        });
    });
});
