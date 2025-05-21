function calcularDiasLaborables(startDate, endDate) {
    const start = new Date(startDate);
    const end = new Date(endDate);
    let count = 0;
    
    if (start > end) return 0;
    
    const current = new Date(start);
    while (current <= end) {
        const day = current.getDay();
        if (day !== 0 && day !== 6) {
            count++;
        }
        current.setDate(current.getDate() + 1);
    }
    return count;
}

document.getElementById('vacationStartDate').addEventListener('change', calcularDiasSolicitados);
document.getElementById('vacationEndDate').addEventListener('change', calcularDiasSolicitados);

function calcularDiasSolicitados() {
    const startDate = document.getElementById('vacationStartDate').value;
    const endDate = document.getElementById('vacationEndDate').value;
    
    if (startDate && endDate) {
        const dias = calcularDiasLaborables(startDate, endDate);
        document.getElementById('daysRequested').value = dias > 0 ? dias : '';
    }
}

// Función para sanitizar/escapar valores de entrada
function sanitizeInput(input) {
    if (typeof input !== 'string') return input;
    
    // Eliminar caracteres potencialmente peligrosos
    return input
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/'/g, '&#39;')
        .replace(/"/g, '&quot;')
        .replace(/\\/g, '&#92;')
        .replace(/\//g, '&#47;')
        .replace(/\|/g, '&#124;');
}

// Función para validar campos específicos
function validateField(field, value) {
    switch(field) {
        case 'employeeNumber':
            return /^\d+$/.test(value) && value.length <= 10;
            
        case 'department':
            return /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]{2,50}$/.test(value);
            
        case 'email':
        case 'supervisorEmail':
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) && value.length <= 100;
            
        case 'supervisorName':
            return /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-\.]{2,50}$/.test(value);
            
        case 'daysRequested':
            return /^\d+$/.test(value) && parseInt(value) > 0 && parseInt(value) <= 30;
            
        case 'comments':
            return value.length <= 500;
            
        default:
            return true;
    }
}

// Función para procesar y validar todos los campos del formulario
function processFormData(formData) {
    const processed = {};
    let errors = [];
    
    // Procesar cada campo
    for (const [key, value] of Object.entries(formData)) {
        const sanitizedValue = sanitizeInput(value);
        
        if (!validateField(key, sanitizedValue)) {
            errors.push(`El campo ${key} contiene valores inválidos`);
        }
        
        processed[key] = sanitizedValue;
    }
    
    // Validación adicional de fechas
    const startDate = new Date(processed.vacationStartDate);
    const endDate = new Date(processed.vacationEndDate);
    
    if (startDate >= endDate) {
        errors.push('La fecha de fin debe ser posterior a la fecha de inicio');
    }
    
    if (errors.length > 0) {
        throw new Error(errors.join('\n'));
    }
    
    return processed;
}

// Validación en tiempo real para campos
document.getElementById('employeeNumber').addEventListener('input', function(e) {
    if (!/^\d*$/.test(this.value)) {
        this.value = this.value.replace(/[^\d]/g, '');
    }
});

document.getElementById('department').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]/g, '');
});

document.getElementById('supervisorName').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-\.]/g, '');
});

// Event listener principal del formulario
document.getElementById('vacationForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Enviando...';
    
    try {
        // Obtener y procesar los datos del formulario
        const rawFormData = {
            employeeNumber: document.getElementById('employeeNumber').value.trim(),
            department: document.getElementById('department').value.trim(),
            company: document.getElementById('company').value,
            email: document.getElementById('email').value.trim(),
            vacationStartDate: document.getElementById('vacationStartDate').value,
            vacationEndDate: document.getElementById('vacationEndDate').value,
            daysRequested: document.getElementById('daysRequested').value,
            supervisorName: document.getElementById('supervisorName').value.trim(),
            supervisorEmail: document.getElementById('supervisorEmail').value.trim(),
            comments: document.getElementById('comments').value.trim()
        };
        
        const formData = processFormData(rawFormData);
        
        const response = await fetch('../backend/guardar_solicitud.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const responseText = await response.text();
        let result;
        
        try {
            result = JSON.parse(responseText);
        } catch (e) {
            console.error('Respuesta no JSON:', responseText);
            throw new Error('Error inesperado del servidor');
        }
        
        if (!response.ok || !result.success) {
            if (result.data && result.data.dias_restantes !== undefined) {
                throw new Error(`${result.message}\nDías disponibles: ${result.data.dias_restantes}\nDías solicitados: ${result.data.dias_solicitados}`);
            }
            throw new Error(result.message || 'Error al procesar solicitud');
        }
        
        alert(`✅ ${result.message}\n
              ID Solicitud: ${result.data.id}\n
              Empleado: ${result.data.nombre_empleado} (${result.data.employee})\n
              Se ha notificado al supervisor: ${formData.supervisorEmail}`);
        
        document.getElementById('vacationForm').reset();
        
    } catch (error) {
        console.error(error);
        if (error.message.includes('No tiene suficientes días')) {
            alert(`⚠️ ${error.message}`);
        } else {
            alert(`❌ Error:\n${error.message}`);
        }
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});