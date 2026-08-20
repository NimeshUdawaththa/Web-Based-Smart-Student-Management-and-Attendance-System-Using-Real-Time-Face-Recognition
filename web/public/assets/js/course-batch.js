document.addEventListener('DOMContentLoaded', () => {
    const dataElement = document.getElementById('batch-options-data');
    if (dataElement) {
        let batchOptions = {};
        try {
            batchOptions = JSON.parse(dataElement.textContent || '{}');
        } catch (error) {
            batchOptions = {};
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
                    option.textContent = batch.label || `${batch.batch_name} (${batch.intake_year})`;
                    batchSelect.appendChild(option);
                });

                if (currentValue && batches.some((batch) => String(batch.batch_id) === currentValue)) {
                    batchSelect.value = currentValue;
                }
            };

            courseSelect.addEventListener('change', refreshBatches);
        });
    }

    const enrolModulesElement = document.getElementById('enrol-modules-by-batch');
    const enrolBatchSelect = document.getElementById('batch_id');
    const enrolModuleSelect = document.getElementById('module_id');
    if (enrolModulesElement && enrolBatchSelect && enrolModuleSelect && enrolBatchSelect.dataset.moduleTarget === 'module_id') {
        let modulesByBatch = {};
        try {
            modulesByBatch = JSON.parse(enrolModulesElement.textContent || '{}');
        } catch (error) {
            modulesByBatch = {};
        }

        const refreshModules = () => {
            const batchId = enrolBatchSelect.value;
            const currentValue = enrolModuleSelect.value;
            const modules = modulesByBatch[batchId] || [];
            enrolModuleSelect.innerHTML = '<option value="">Select module</option>';
            modules.forEach((module) => {
                const option = document.createElement('option');
                option.value = String(module.module_id);
                option.textContent = module.label;
                enrolModuleSelect.appendChild(option);
            });
            if (currentValue && modules.some((module) => String(module.module_id) === currentValue)) {
                enrolModuleSelect.value = currentValue;
            }
        };

        enrolBatchSelect.addEventListener('change', refreshModules);
        refreshModules();
    }

    const setupRows = document.getElementById('course-module-setup-rows');
    const setupAdd = document.getElementById('course-module-setup-add');
    const setupTemplate = document.getElementById('course-module-setup-template');
    if (setupRows && setupAdd && setupTemplate) {
        const reindex = () => {
            setupRows.querySelectorAll('.module-setup-row').forEach((row, index) => {
                const title = row.querySelector('strong');
                if (title) {
                    title.textContent = 'Module ' + (index + 1);
                }
                row.querySelectorAll('[name]').forEach((input) => {
                    const name = input.getAttribute('name') || '';
                    input.setAttribute('name', name.replace(/modules\[\d+]/, 'modules[' + index + ']'));
                });
            });
        };

        setupAdd.addEventListener('click', () => {
            const html = setupTemplate.innerHTML.replaceAll('__INDEX__', String(setupRows.querySelectorAll('.module-setup-row').length));
            setupRows.insertAdjacentHTML('beforeend', html);
            reindex();
        });

        setupRows.addEventListener('click', (event) => {
            const button = event.target.closest('.module-setup-remove');
            if (!button) {
                return;
            }
            const row = button.closest('.module-setup-row');
            if (row && setupRows.querySelectorAll('.module-setup-row').length > 1) {
                row.remove();
                reindex();
            }
        });
    }
});
