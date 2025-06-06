function showTab(tab) {
            // Cambiar tabs activos
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector('.tab').classList.add('active');
            event.target.classList.add('active');
            
            // Mostrar sección correspondiente
            document.querySelectorAll('.form-section').forEach(s => s.classList.remove('active'));
            document.getElementById(tab + '-section').classList.add('active');
        }

        function toggleEmpresaForm() {
            const login = document.getElementById('empresa-login');
            const registro = document.getElementById('empresa-registro');
            
            if (login.style.display === 'none') {
                login.style.display = 'block';
                registro.style.display = 'none';
            } else {
                login.style.display = 'none';
                registro.style.display = 'block';
            }
        }

        function toggleUsuarioForm() {
            const login = document.getElementById('usuario-login');
            const registro = document.getElementById('usuario-registro');
            
            if (login.style.display === 'none') {
                login.style.display = 'block';
                registro.style.display = 'none';
            } else {
                login.style.display = 'none';
                registro.style.display = 'block';
            }
        }

        function updateFileName(input) {
            const label = document.getElementById('file-label');
            if (input.files && input.files[0]) {
                const fileName = input.files[0].name;
                const fileSize = (input.files[0].size / 1024 / 1024).toFixed(2);
                label.innerHTML = `<span class="file-selected">✓ ${fileName} (${fileSize} MB)</span>`;
            } else {
                label.innerHTML = '📄 Seleccionar archivo PDF (máx. 5MB)';
            }
        }