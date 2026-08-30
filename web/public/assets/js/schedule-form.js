document.addEventListener('DOMContentLoaded', () => {
    const dataElement = document.getElementById('schedule-options-data');
    const moduleSelect = document.getElementById('module_id');
    const lecturerSelect = document.getElementById('lecturer_id');
    const batchSelect = document.getElementById('batch_id');
    if (!dataElement || !moduleSelect || !lecturerSelect || !batchSelect) {
        return;
    }

    let options = {};
    try {
        options = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        return;
    }

    const refresh = () => {
        const moduleId = moduleSelect.value;
        const courseIdsRaw = options.modules ? options.modules[moduleId] : [];
        const courseIds = Array.isArray(courseIdsRaw)
            ? courseIdsRaw
            : (courseIdsRaw ? [courseIdsRaw] : []);
        const lecturers = (options.lecturers && options.lecturers[moduleId]) ? options.lecturers[moduleId] : [];
        let batches = [];
        courseIds.forEach((courseId) => {
            const courseBatches = (options.batches && options.batches[courseId]) ? options.batches[courseId] : [];
            courseBatches.forEach((batch) => {
                if (!batches.some((item) => String(item.batch_id) === String(batch.batch_id))) {
                    batches.push(batch);
                }
            });
        });
        const selectedLecturer = lecturerSelect.value || options.selectedLecturer || '';
        const selectedBatch = batchSelect.value || options.selectedBatch || '';

        lecturerSelect.innerHTML = '<option value="">Select lecturer</option>';
        lecturers.forEach((lecturer) => {
            const option = document.createElement('option');
            option.value = String(lecturer.lecturer_id);
            option.textContent = `${lecturer.name} (${lecturer.staff_no})`;
            lecturerSelect.appendChild(option);
        });
        if (selectedLecturer && lecturers.some((item) => String(item.lecturer_id) === String(selectedLecturer))) {
            lecturerSelect.value = String(selectedLecturer);
        }

        batchSelect.innerHTML = '<option value="">Select batch</option>';
        batches.forEach((batch) => {
            const option = document.createElement('option');
            option.value = String(batch.batch_id);
            option.textContent = `${batch.batch_name} (${batch.intake_year})`;
            batchSelect.appendChild(option);
        });
        if (selectedBatch && batches.some((item) => String(item.batch_id) === String(selectedBatch))) {
            batchSelect.value = String(selectedBatch);
        }
    };

    moduleSelect.addEventListener('change', () => {
        options.selectedLecturer = '';
        options.selectedBatch = '';
        refresh();
    });
    refresh();
});
