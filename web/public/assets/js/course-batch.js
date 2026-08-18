document.addEventListener('DOMContentLoaded', () => {
    const dataElement = document.getElementById('batch-options-data');
    if (!dataElement) {
        return;
    }

    let batchOptions = {};
    try {
        batchOptions = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        return;
    }

    document.querySelectorAll('.course-select').forEach((courseSelect) => {
        const batchTargetId = courseSelect.dataset.batchTarget;
        const batchSelect = batchTargetId ? document.getElementById(batchTargetId) : null;

        if (!batchSelect) {
            return;
        }

        const refreshBatches = () => {
            const courseId = courseSelect.value;
            const currentValue = batchSelect.value;
            const batches = batchOptions[courseId] || [];

            batchSelect.innerHTML = '<option value="">Select batch</option>';
            batches.forEach((batch) => {
                const option = document.createElement('option');
                option.value = String(batch.batch_id);
                option.textContent = `${batch.batch_name} (${batch.intake_year})`;
                batchSelect.appendChild(option);
            });

            if (currentValue && batches.some((batch) => String(batch.batch_id) === currentValue)) {
                batchSelect.value = currentValue;
            }
        };

        courseSelect.addEventListener('change', refreshBatches);
    });
});
