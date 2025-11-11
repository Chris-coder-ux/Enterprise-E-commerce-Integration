/**
 * Inicialización del calendario Cally en el dashboard
 * 
 * Este archivo se encarga de configurar y mostrar el calendario
 * en el div del dashboard de administración.
 * 
 * @package MiIntegracionApi
 * @since 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Inicializa el calendario cuando el DOM esté listo
     */
    $(document).ready(function() {
        initializeCalendar();
    });

    /**
     * Inicializa el calendario Cally
     */
    function initializeCalendar() {
        const calendarContainer = document.querySelector('.mi-integracion-api-calendar');
        
        if (!calendarContainer) {
            return;
        }

        // Esperar a que Cally se cargue si no está disponible inmediatamente
        if (typeof window.Cally === 'undefined') {
            // Intentar cargar Cally después de un breve delay
            setTimeout(() => {
                if (typeof window.Cally !== 'undefined') {
                    initializeCalendar();
                } else {
                    console.error('Mi Integración API: Cally no se pudo cargar. Mostrando calendario básico.');
                    showBasicCalendar(calendarContainer);
                }
            }, 1000);
            return;
        }

        try {

            // Configurar el calendario
            const calendar = new window.Cally({
                // Configuración básica
                locale: 'es', // Español
                firstDayOfWeek: 1, // Lunes como primer día
                showWeekNumbers: true,
                
                // Estilos personalizados
                theme: 'light',
                
                // Eventos personalizados
                onDateClick: function(date) {
                    // El problema está en que Cally está pasando el día anterior
                    // Vamos a corregir esto sumando un día
                    const correctedDate = new Date(date);
                    correctedDate.setDate(correctedDate.getDate() + 1);
                    
                    // Corregir la selección visual en el calendario
                    setTimeout(() => {
                        correctCalendarVisualSelection(correctedDate);
                    }, 100);
                    
                    handleDateClick(correctedDate);
                },
                
                onMonthChange: function(month, year) {
                    handleMonthChange(month, year);
                }
            });

            // Renderizar el calendario en el contenedor
            calendar.render(calendarContainer);


        } catch (error) {
            console.error('Mi Integración API: Error al inicializar el calendario:', error);
            
            // Mostrar mensaje de error en el contenedor
            calendarContainer.innerHTML = `
                <div class="calendar-error">
                    <p>Error al cargar el calendario. Por favor, recarga la página.</p>
                </div>
            `;
        }
    }

    /**
     * Corrige la selección visual en el calendario
     * 
     * @param {Date} correctedDate Fecha corregida
     */
    function correctCalendarVisualSelection(correctedDate) {
        const calendarContainer = document.querySelector('.mi-integracion-api-calendar');
        if (!calendarContainer) return;
        
        // Remover selección anterior
        const previousSelected = calendarContainer.querySelector('.cally-day.cally-selected');
        if (previousSelected) {
            previousSelected.classList.remove('cally-selected');
        }
        
        // Buscar y seleccionar el día correcto
        const dayElements = calendarContainer.querySelectorAll('.cally-day');
        dayElements.forEach(dayElement => {
            const dayText = dayElement.textContent.trim();
            const dayNumber = parseInt(dayText);
            
            if (dayNumber === correctedDate.getDate()) {
                dayElement.classList.add('cally-selected');
            }
        });
    }

    /**
     * Maneja el clic en una fecha del calendario
     * 
     * @param {Date} date Fecha seleccionada (ya corregida)
     */
    function handleDateClick(date) {
        // Aquí puedes agregar lógica personalizada para cuando se selecciona una fecha
        // Por ejemplo, mostrar información adicional o realizar acciones específicas
    }

    /**
     * Maneja el cambio de mes en el calendario
     * 
     * @param {number} month Mes actual
     * @param {number} year Año actual
     */
    function handleMonthChange(month, year) {
        // Aquí puedes agregar lógica para el cambio de mes
        // Por ejemplo, actualizar información específica del mes
    }

    /**
     * Función placeholder para futuras funcionalidades
     * 
     * @param {Object} data Datos
     */
    function addEventToCalendar(data) {
        // Función placeholder para futuras funcionalidades
        // Por ejemplo, añadir marcadores visuales al calendario
    }

    /**
     * Muestra un calendario básico como fallback si Cally no está disponible
     * 
     * @param {HTMLElement} container Contenedor del calendario
     */
    function showBasicCalendar(container) {
        const today = new Date();
        const currentMonth = today.getMonth();
        const currentYear = today.getFullYear();
        
        const monthNames = [
            'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
        ];
        
        const dayNames = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        
        // Generar HTML del calendario básico
        const calendarHTML = `
            <div class="basic-calendar">
                <div class="calendar-header">
                    <h3>${monthNames[currentMonth]} ${currentYear}</h3>
                </div>
                <div class="calendar-weekdays">
                    ${dayNames.map(day => `<div class="calendar-weekday">${day}</div>`).join('')}
                </div>
                <div class="calendar-days" id="calendar-days">
                    <!-- Los días se generarán con JavaScript -->
                </div>
            </div>
        `;
        
        container.innerHTML = calendarHTML;
        
        // Generar los días del mes
        generateBasicCalendarDays(currentMonth, currentYear);
    }
    
    /**
     * Genera los días del calendario básico
     * 
     * @param {number} month Mes (0-11)
     * @param {number} year Año
     */
    function generateBasicCalendarDays(month, year) {
        const daysContainer = document.getElementById('calendar-days');
        if (!daysContainer) return;
        
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const daysInMonth = lastDay.getDate();
        const startingDayOfWeek = firstDay.getDay();
        
        let daysHTML = '';
        
        // Días del mes anterior (para completar la primera semana)
        for (let i = 0; i < startingDayOfWeek; i++) {
            daysHTML += '<div class="calendar-day other-month"></div>';
        }
        
        // Días del mes actual
        for (let day = 1; day <= daysInMonth; day++) {
            const isToday = day === new Date().getDate() && month === new Date().getMonth() && year === new Date().getFullYear();
            const todayClass = isToday ? ' today' : '';
            daysHTML += `<div class="calendar-day${todayClass}" data-day="${day}">${day}</div>`;
        }
        
        daysContainer.innerHTML = daysHTML;
        
        // Añadir event listeners a los días
        const dayElements = daysContainer.querySelectorAll('.calendar-day:not(.other-month)');
        dayElements.forEach(dayElement => {
            dayElement.addEventListener('click', function() {
                const day = this.getAttribute('data-day');
                // Crear la fecha correctamente (month ya es 0-indexado en generateBasicCalendarDays)
                const date = new Date(year, month, parseInt(day));
                console.log('Mi Integración API: Fecha creada:', date, 'Día:', day, 'Mes:', month, 'Año:', year);
                handleDateClick(date);
            });
        });
    }

    // Exponer funciones globalmente para uso desde otros scripts
    window.MiIntegracionApiCalendar = {
        addEvent: addEventToCalendar,
        initialize: initializeCalendar
    };

})(jQuery);
