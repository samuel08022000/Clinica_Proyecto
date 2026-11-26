"""Práctica H4. Ejecutar con Python 3. Requiere numpy.
Para mostrar las gráficas: python "Practica H4.py" --graficas (requiere matplotlib).
Para imprimir cada iteración de las mallas finas, añadir --detalle.
"""
import argparse
from math import exp, expm1, sqrt
from time import perf_counter

import numpy as np


def pendiente_18(x, y):
    return 1 / x**2 - y**2 - y / x


# Número, f(x,y), exacta, inicio, fin, valor inicial, pasos y métodos.
PROBLEMAS = [
    (15, lambda x, y: -2*x*y**2, lambda x: 1/x**2,
     1, 2, 1, [0.25], ["RK4"]),
    (16, lambda x, y: (1-y)*(4-y), lambda x: 4*expm1(3*x)/(4*exp(3*x)-1),
     0, 1, 0, [0.25, 0.1, 0.01], ["RK4"]),
    (17, lambda x, y: y+x**2, lambda x: 6*exp(x-1)-x**2-2*x-2,
     1, 2, 1, [0.1, 0.01, 0.001, 0.0001], ["RK4"]),
    (18, pendiente_18, lambda x: -1/x, 1, 2, -1, [0.25], ["RK4"]),
    (19, pendiente_18, lambda x: -1/x, 1, 2, -1, [0.05], ["Euler", "RK4"]),
    (20, lambda x, y: 2*y/x+x**2*exp(x), lambda x: x**2*(exp(x)-exp(1)),
     1, 2, 0, [0.1], ["RK4"]),
    (21, lambda x, y: exp(x)/y, lambda x: sqrt(2*exp(x)-1),
     0, 2, 1, [0.5], ["Euler", "RK4"]),
    (22, lambda x, y: 4*y/x+x**4*exp(x), lambda x: x**4*(exp(x)-exp(1)),
     1, 2, 0, [0.25], ["Euler", "RK4"]),
]


def integrar(f, a, b, inicial, h, metodo):
    y = inicial
    puntos = [(a, y)]
    for i in range(round((b-a)/h)):
        x = a+i*h
        k1 = f(x, y)
        if metodo == "Euler":
            y += h*k1
        else:
            k2 = f(x+h/2, y+h*k1/2)
            k3 = f(x+h/2, y+h*k2/2)
            k4 = f(x+h, y+h*k3)
            y += h*(k1+2*k2+2*k3+k4)/6
        puntos.append((a+(i+1)*h, y))
    return puntos


def capitulo_7(detalle, graficas):
    if graficas:
        import matplotlib.pyplot as plt
    for n, f, exacta, a, b, inicial, pasos, metodos in PROBLEMAS:
        print(f"\nCapítulo 7. Ejercicio {n}")
        if graficas:
            _, eje = plt.subplots()
            xs = np.linspace(a, b, 301)
            eje.plot(xs, [exacta(x) for x in xs], "k--", label="Exacta")
        for h in pasos:
            for metodo in metodos:
                inicio = perf_counter()
                puntos = integrar(f, a, b, inicial, h, metodo)
                tiempo = perf_counter()-inicio
                print(f"{metodo}, h={h}\n       x      aproximación          exacta   error verdadero")
                salto = 1 if detalle or len(puntos)<=21 else (len(puntos)-1)//10
                for i, (x, y) in enumerate(puntos):
                    if i % salto == 0 or i == len(puntos)-1:
                        print(f"{x:8.4f} {y:17.10f} {exacta(x):15.10f} {exacta(x)-y:16.5e}")
                if n == 19:
                    evaluaciones = (len(puntos)-1)*(1 if metodo == "Euler" else 4)
                    print(f"Evaluaciones de f: {evaluaciones}. Tiempo de integración: {tiempo:.6g} s.")
                if graficas:
                    eje.plot(*zip(*puntos), label=f"{metodo}, h={h}")
        if graficas:
            eje.set(xlabel="x", ylabel="y", title=f"Capítulo 7. Ejercicio {n}")
            eje.legend()
    if graficas:
        plt.show()


def clasificacion():
    print("\nCapítulo 8. Ejercicio 1. D = B² - AC para A uxx + 2B uxy + C uyy.")
    print("a) A=1-M², B=0, C=1. D=M²-1. Flujo subsónico: elíptica; diferencias centrales.")
    print("b) A=ν, B=C=0. D=0: parabólica; avance temporal por Crank-Nicolson.")
    print("c) A=x²-1, B=y, C=-1. D=x²+y²-1. Dentro del círculo: elíptica; "
          "en el círculo: parabólica; fuera: hiperbólica. Diferencias centrales "
          "en el interior y características en el exterior.")
    print("d) A=C=1, B=0. D=-1: elíptica; Poisson con cinco puntos.")
    print("e) A=-a, B=C=0. D=0: parabólica; Crank-Nicolson.")


def resolver_malla(n, nx, ny, ancho, alto, detalle):
    # Filas: coordenada y. Columnas: coordenada x. Todos los ejercicios tienen dx=dy.
    x = np.linspace(0, ancho, nx+1)
    y = np.linspace(0, alto, ny+1)[:, None]
    h = ancho/nx
    fuente = 0
    if n == 4:
        exacta = np.sin(np.pi*x/2)*np.sinh(np.pi*y/2)/np.sinh(np.pi/2)
    elif n == 7:
        exacta = (x-y)**2
        fuente = 4
    else:
        exacta = x*y
    u = np.zeros_like(exacta)
    # Solo los bordes se conocen al comenzar.
    u[[0,-1],:] = exacta[[0,-1],:]
    u[:,[0,-1]] = exacta[:,[0,-1]]
    interior = u[1:-1,1:-1]
    j, i = np.indices(interior.shape)
    grupos = [(i+j)%2 == color for color in (0,1)]
    omega = 1.9 if nx >= 100 else 1.0
    for vuelta in range(1, 100001):
        cambio = 0.0
        for grupo in grupos:
            media = (u[:-2,1:-1]+u[2:,1:-1]+u[1:-1,:-2]+u[1:-1,2:]-h*h*fuente)/4
            delta = omega*(media[grupo]-interior[grupo])
            interior[grupo] += delta
            cambio = max(cambio, np.max(np.abs(delta)))
        if cambio < 1e-13:
            break
    else:
        raise RuntimeError("La malla no convergió.")
    print(f"\nCapítulo 8. Ejercicio {n}. Malla {nx} x {ny}, h={h:.6g}.")
    print("Regla: 4P = E + O + N + S - h²·fuente. Fuente =", fuente)
    print(f"Barridos: {vuelta}. Error absoluto máximo: {np.max(np.abs(u-exacta)):.6e}")
    print("       x        y       aproximación          exacta")
    salto = 1 if detalle or nx < 100 else 25
    for j in range(1 if salto == 1 else salto, ny, salto):
        for i in range(1 if salto == 1 else salto, nx, salto):
            print(f"{x[i]:8.4f} {y[j,0]:8.4f} {u[j,i]:17.10f} {exacta[j,i]:15.10f}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--detalle", action="store_true")
    parser.add_argument("--graficas", action="store_true")
    opciones = parser.parse_args()
    capitulo_7(opciones.detalle, opciones.graficas)
    clasificacion()
    for datos in [(2,3,3,1,1), (3,100,100,1,1), (4,200,100,2,1), (7,2,4,1,2)]:
        resolver_malla(*datos, opciones.detalle)
