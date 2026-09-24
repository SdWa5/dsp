"""Konfiguration per TCP lesen und vergleichen (Absicherung für GUI-Tests)."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from trackdsp import Matrix

TARGET = "192.168.68.10"


_dsp = None


def read(attempts: int = 3):
    """Konfiguration über eine dauerhafte TCP-Verbindung lesen (bei Fehler neu verbinden)."""
    global _dsp
    import time
    for i in range(attempts):
        try:
            if _dsp is None:
                _dsp = Matrix.connect(TARGET, timeout=1.5)
            return _dsp.config()
        except Exception:
            if _dsp is not None:
                _dsp.close()
            _dsp = None
            time.sleep(2)
    raise RuntimeError("Konfiguration nicht lesbar")


def diff(a, b):
    return [(i, a[i], b[i]) for i in range(len(a)) if a[i] != b[i]]


def where(i):
    if i < 16: return f"Kopf+{i}"
    if i < 512: return f"In{(i-16)//62+1}+{(i-16)%62}"
    if i < 606: return f"Aux+{i-512}"
    if i < 1214: return f"Out{(i-606)//76+1}+{(i-606)%76}"
    return f"Fuß+{i-1214}"
